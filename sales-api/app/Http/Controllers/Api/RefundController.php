<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Refund;
use App\Services\Buster\GatewayFactory;
use App\Services\Buster\StationRegistry;
use App\Support\VendorCode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RefundController extends Controller
{
    public function __construct(
        private readonly GatewayFactory $gateways,
        private readonly StationRegistry $stations,
    ) {}

    /**
     * Возврат/аннулирование билетов: POST /api/v1/refunds
     * {awtcod, operation: 1|5, preview: bool, tickets: [{serial, number}]}
     * preview=true — только рассчитать сумму возврата, ничего не возвращая.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'awtcod' => VendorCode::rules(),
            'operation' => ['required', 'integer', 'in:1,5'],
            'preview' => ['sometimes', 'boolean'],
            // Админский возврат (legacy admin_mode): разрешён после отправления.
            'admin_mode' => ['sometimes', 'boolean'],
            'tickets' => ['required', 'array', 'min:1', 'max:20'],
            'tickets.*.serial' => ['required', 'string', 'max:20'],
            'tickets.*.number' => ['required', 'string', 'max:20'],
            /*
             * Дата, на которую перенести запись возврата в журнале станции.
             * Нужна для возвратов, сделанных при недоступной станции:
             * деньги покупателю вернули тогда, а аннулирование дошло
             * позже — см. BusterGateway::backdateRefundRows. Только с
             * admin_mode: это инструмент кассира, не продажа через сайт.
             */
            'backdate' => ['sometimes', 'nullable', 'date', 'before_or_equal:now'],
        ]);

        $agent = $request->attributes->get('agent');
        $preview = (bool) ($data['preview'] ?? false);
        $adminMode = (bool) ($data['admin_mode'] ?? false);

        /*
         * Выключатель возврата по станции (АИС) — это про возврат через
         * сайт. Админский возврат (инструмент кассира в АИС) его не
         * слушает, как и общий выключатель возвратов сайта: кассиру
         * вернуть билет нужно всегда.
         */
        if (! $adminMode) {
            $this->stations->assertRefundsEnabled($data['awtcod']);
        }

        $gateway = $this->gateways->forStation($data['awtcod'], $agent);

        $result = $gateway->refund(
            (int) $data['awtcod'],
            $data['tickets'],
            (int) $data['operation'],
            $preview,
            $adminMode,
            // Переносить дату вправе только админский возврат.
            $adminMode ? ($data['backdate'] ?? null) : null,
        );

        $refund = Refund::create([
            'agent_id' => $agent['id'],
            'awtcod' => $data['awtcod'],
            'operation' => $data['operation'],
            'preview' => $preview,
            'tickets' => $data['tickets'],
            'total_refund' => $result['total_refund'],
            'raw_response' => $result['raw'],
        ]);

        return response()->json([
            'success' => true,
            'data' => [
                'refund_id' => $refund->id,
                'preview' => $preview,
                'total_refund' => $result['total_refund'],
                'places' => $result['places'],
                'prices' => $result['prices'],
                'penalties' => $result['penalties'],
                'trip_name' => $result['trip_name'],
                'datetime_start' => $result['datetime_start'],
                // Итог переноса даты в журнале станции (null — не просили).
                'backdate' => $result['backdate'] ?? null,
                /*
                 * Результат по КАЖДОМУ бланку. Признаком успеха сумма
                 * возврата быть не может: станция честно отдаёт ноль за
                 * бесплатную багажную квитанцию и при полном удержании —
                 * а вызывающей стороне нужно знать, что бланки приняты.
                 */
                'blanks' => $result['blanks'] ?? [],
            ],
        ], 201);
    }
}
