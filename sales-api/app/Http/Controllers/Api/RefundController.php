<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Refund;
use App\Services\Buster\GatewayFactory;
use App\Services\Buster\StationRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use App\Support\VendorCode;

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
            ],
        ], 201);
    }
}
