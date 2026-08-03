<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Reservation;
use App\Models\TicketSale;
use App\Services\Buster\GatewayFactory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SaleController extends Controller
{
    public function __construct(private readonly GatewayFactory $gateways) {}

    /**
     * Оформить (продать) зарезервированные места: POST /api/v1/sales
     * Продажа по reservation_id (наш резерв) либо по явному id_time_places.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'reservation_id' => ['required_without:id_time_places', 'uuid'],
            'id_time_places' => ['required_without:reservation_id', 'string', 'max:32'],
            'awtcod' => ['required_without:reservation_id', 'integer'],
            'napcod' => ['required_without:reservation_id', 'integer'],
            'raicecod' => ['required_without:reservation_id', 'integer'],
            'statcod' => ['required_without:reservation_id', 'integer'],
            'date' => ['required_without:reservation_id', 'date_format:Y-m-d'],
            'tickets' => ['required', 'array', 'min:1', 'max:20'],
            'tickets.*.place' => ['required'],
            'tickets.*.type' => ['required', 'in:П,Д'],
            'tickets.*.price' => ['required', 'numeric', 'min:0'],
            'tickets.*.last_name' => ['required', 'string', 'max:40'],
            'tickets.*.first_name' => ['required', 'string', 'max:30'],
            'tickets.*.middle_name' => ['nullable', 'string', 'max:30'],
            'tickets.*.birth_date' => ['required', 'date'],
            'tickets.*.doc_type' => ['sometimes', 'integer'],
            'tickets.*.doc_series' => ['nullable', 'string', 'max:6'],
            'tickets.*.doc_number' => ['required', 'string', 'max:25'],
            'tickets.*.citizenship' => ['sometimes', 'integer'],
            'tickets.*.baggage' => ['sometimes', 'integer', 'min:0', 'max:5'],
            'tickets.*.price_bag' => ['sometimes', 'numeric', 'min:0'],
            'tickets.*.insurance_price' => ['sometimes', 'numeric', 'min:0'],
            'tickets.*.comment' => ['nullable', 'string', 'max:40'],
        ]);

        $agent = $request->attributes->get('agent');

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
