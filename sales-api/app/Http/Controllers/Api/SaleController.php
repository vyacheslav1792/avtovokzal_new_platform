<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Reservation;
use App\Models\TicketSale;
use App\Services\Buster\GatewayFactory;
use App\Services\Buster\StationRegistry;
use App\Support\VendorCode;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class SaleController extends Controller
{
    public function __construct(
        private readonly GatewayFactory $gateways,
        private readonly StationRegistry $stations,
    ) {}

    /**
     * Оформить (продать) зарезервированные места: POST /api/v1/sales
     * Продажа по reservation_id (наш резерв) либо по явному id_time_places.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'reservation_id' => ['required_without:id_time_places', 'uuid'],
            'id_time_places' => ['required_without:reservation_id', 'string', 'max:32'],
            'awtcod' => ['required_without:reservation_id', ...VendorCode::rules(required: false)],
            'napcod' => ['required_without:reservation_id', ...VendorCode::rules(required: false)],
            'raicecod' => ['required_without:reservation_id', ...VendorCode::rules(required: false)],
            'statcod' => ['required_without:reservation_id', ...VendorCode::rules(required: false)],
            'date' => ['required_without:reservation_id', 'date_format:Y-m-d'],
            'tickets' => ['required', 'array', 'min:1', 'max:20'],
            /*
             * Место — SMALLINT в SALONINFO/SALE (N_PLACE). Без границ
             * сюда проходило любое число, и станция отвечала «-303
             * numeric overflow», то есть 500 вместо внятного отказа.
             * Тип строковый сознательно (в запросах места приходят и
             * строкой), поэтому границы — отдельным правилом.
             */
            'tickets.*.place' => ['required', 'integer', 'min:1', 'max:'.VendorCode::MAX],
            'tickets.*.type' => ['required', 'in:П,Д'],
            'tickets.*.price' => ['required', 'numeric', 'min:0'],
            'tickets.*.last_name' => ['required', 'string', 'max:40'],
            'tickets.*.first_name' => ['required', 'string', 'max:30'],
            'tickets.*.middle_name' => ['nullable', 'string', 'max:30'],
            'tickets.*.birth_date' => ['required', 'date'],
            // DOCUMCOD_PASS в SALE — SMALLINT.
            'tickets.*.doc_type' => ['sometimes', ...VendorCode::boundsFrom(0)],
            'tickets.*.doc_series' => ['nullable', 'string', 'max:6'],
            'tickets.*.doc_number' => ['required', 'string', 'max:25'],
            // GRAGD_ID в SALE — SMALLINT.
            'tickets.*.citizenship' => ['sometimes', ...VendorCode::boundsFrom(0)],
            'tickets.*.baggage' => ['sometimes', 'integer', 'min:0', 'max:5'],
            'tickets.*.price_bag' => ['sometimes', 'numeric', 'min:0'],
            'tickets.*.insurance_price' => ['sometimes', 'numeric', 'min:0'],
            'tickets.*.comment' => ['nullable', 'string', 'max:40'],
        ]);

        $agent = $request->attributes->get('agent');

        /*
         * Один резерв оформляется строго по одному разу за раз.
         *
         * Статус `sold` ставится ПОСЛЕ успешной продажи, поэтому два
         * запроса, пришедшие в одну секунду, оба видели `reserved` и оба
         * шли в KS_SALE_PLACES по одному месту — Firebird отвечал дедлоком
         * (ловилось на проде 15.11.2026). Блокировка выстраивает их в
         * очередь: второй дождётся первого и получит честный `already_sold`.
         */
        if (empty($data['reservation_id'])) {
            return $this->sell($data, $agent);
        }

        $lock = Cache::lock('sale:'.$data['reservation_id'], 180);

        try {
            $lock->block(30);
        } catch (LockTimeoutException) {
            return response()->json([
                'success' => false,
                'error' => 'Оформление по этому резерву уже выполняется',
                'error_code' => 'sale_in_progress',
            ], 409);
        }

        try {
            return $this->sell($data, $agent);
        } finally {
            $lock->release();
        }
    }

    /**
     * Собственно продажа. Вынесена из store(), чтобы блокировка по резерву
     * охватывала и проверку статуса, и обращение к станции.
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $agent
     */
    private function sell(array $data, array $agent): JsonResponse
    {
        $reservation = null;
        if (! empty($data['reservation_id'])) {
            $reservation = Reservation::where('id', $data['reservation_id'])
                ->where('agent_id', $agent['id'])
                ->firstOrFail();

            if ($reservation->status === 'sold') {
                return response()->json([
                    'success' => false,
                    'error' => 'По этому резерву билеты уже оформлены',
                    'error_code' => 'already_sold',
                ], 409);
            }

            $trip = [
                'awtcod' => $reservation->awtcod,
                'napcod' => $reservation->napcod,
                'raicecod' => $reservation->raicecod,
                'statcod' => $reservation->statcod,
                'date' => $reservation->date_start->format('Y-m-d'),
                'id_time_places' => $reservation->id_time_places,
            ];
        } else {
            $trip = [
                'awtcod' => (int) $data['awtcod'],
                'napcod' => (int) $data['napcod'],
                'raicecod' => (int) $data['raicecod'],
                'statcod' => (int) $data['statcod'],
                'date' => $data['date'],
                'id_time_places' => $data['id_time_places'],
            ];
        }

        // Выключатель продажи по станции (АИС). Проверяем и здесь, а не
        // только при брони: оформить можно и по явному id_time_places,
        // без нашего резерва.
        $this->stations->assertSalesEnabled($trip['awtcod']);

        $gateway = $this->gateways->forStation($trip['awtcod'], $agent);

        $result = $gateway->sell(
            $trip['awtcod'],
            $trip['napcod'],
            $trip['raicecod'],
            $trip['statcod'],
            $trip['date'],
            $trip['id_time_places'],
            $data['tickets'],
        );

        $total = collect($result['tickets'])->sum(
            fn (array $t) => (float) $t['price']
                + (int) ($t['baggage'] ?? 0) * (float) ($t['price_bag'] ?? 0)
                + (float) ($t['insurance_price'] ?? 0),
        );

        $sale = TicketSale::create([
            'reservation_id' => $reservation?->id,
            'agent_id' => $agent['id'],
            'awtcod' => $trip['awtcod'],
            'napcod' => $trip['napcod'],
            'raicecod' => $trip['raicecod'],
            'statcod' => $trip['statcod'],
            'date_start' => $trip['date'],
            'id_time_places' => $trip['id_time_places'],
            'tickets' => $result['tickets'],
            'total_price' => $total,
            'raw_response' => $result['raw'],
        ]);

        $reservation?->update(['status' => 'sold']);

        return response()->json([
            'success' => true,
            'data' => [
                'sale_id' => $sale->id,
                'tickets' => $result['tickets'],
                'total_price' => $total,
            ],
        ], 201);
    }
}
