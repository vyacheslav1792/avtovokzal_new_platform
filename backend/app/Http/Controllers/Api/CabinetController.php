<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\CabinetService;
use App\Services\RefundService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Личный кабинет: заказы покупателя и возврат билета из кабинета
 * (перенос legacy url/lk.php и url/ticketback.php). Правила возврата —
 * общие с возвратом по коду (RefundService).
 */
class CabinetController extends Controller
{
    public function __construct(
        private readonly CabinetService $cabinet,
        private readonly RefundService $refunds,
    ) {}

    /** Список заказов: scope=all|upcoming|past. */
    public function orders(Request $request): JsonResponse
    {
        $data = $request->validate([
            'scope' => ['sometimes', 'in:all,upcoming,past'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:50'],
        ]);

        $orders = $this->cabinet->orders(
            $request->user(),
            $data['scope'] ?? 'all',
            (int) ($data['per_page'] ?? 20),
        );

        return response()->json([
            'success' => true,
            'data' => $orders->items(),
            'meta' => [
                'current_page' => $orders->currentPage(),
                'last_page' => $orders->lastPage(),
                'total' => $orders->total(),
            ]
                + $this->cabinet->summary($request->user()),
        ]);
    }

    public function order(Request $request, string $id): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $this->cabinet->order($request->user(), $id),
        ]);
    }

    /** Шаг 1 возврата: условия и подписанный токен подтверждения. */
    public function refundPreview(Request $request): JsonResponse
    {
        $data = $request->validate([
            'order_id' => ['required', 'string', 'max:36'],
            'ticket_id' => ['required', 'uuid'],
        ]);

        $order = $this->cabinet->findOrder($request->user(), $data['order_id']);
        $ticket = $this->cabinet->findTicket($order, $data['ticket_id']);

        return response()->json([
            'success' => true,
            'data' => $this->refunds->previewTicket($order, $ticket),
        ]);
    }

    /** Шаг 2 возврата: тот же путь, что и у возврата по коду. */
    public function refundConfirm(Request $request): JsonResponse
    {
        $data = $request->validate(['confirm_token' => ['required', 'string']]);

        return response()->json([
            'success' => true,
            'data' => $this->refunds->confirm($data['confirm_token']),
        ]);
    }
}
