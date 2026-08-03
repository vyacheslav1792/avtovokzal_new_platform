<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Refund;
use App\Services\Buster\GatewayFactory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RefundController extends Controller
{
    public function __construct(private readonly GatewayFactory $gateways) {}

    /**
     * Возврат/аннулирование билетов: POST /api/v1/refunds
     * {awtcod, operation: 1|5, preview: bool, tickets: [{serial, number}]}
     * preview=true — только рассчитать сумму возврата, ничего не возвращая.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'awtcod' => ['required', 'integer'],
            'operation' => ['required', 'integer', 'in:1,5'],
            'preview' => ['sometimes', 'boolean'],
            'tickets' => ['required', 'array', 'min:1', 'max:20'],
            'tickets.*.serial' => ['required', 'string', 'max:20'],
            'tickets.*.number' => ['required', 'string', 'max:20'],
        ]);

        $agent = $request->attributes->get('agent');
        $preview = (bool) ($data['preview'] ?? false);

        $gateway = $this->gateways->forStation($data['awtcod'], $agent);

        $result = $gateway->refund((int) $data['awtcod'], $data['tickets'], (int) $data['operation'], $preview);

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
            ],
        ], 201);
    }
}
