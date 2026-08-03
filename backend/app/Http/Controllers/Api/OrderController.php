<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\OrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OrderController extends Controller
{
    public function __construct(private readonly OrderService $orders) {}

    /** Создать заказ: резерв мест + регистрация оплаты. */
    public function store(\App\Http\Requests\StoreOrderRequest $request): JsonResponse
    {
        $order = $this->orders->create($request->validated(), (string) $request->ip());

        return response()->json([
            'success' => true,
            'data' => [
                'order_id' => $order->id,
                'total_price' => (float) $order->total_price,
                'payment_url' => $order->payment_url,
                'places' => collect($order->passengers)->pluck('place'),
            ],
        ], 201);
    }

    /** Подтверждение оплаты (возврат со шлюза): оформление билетов. */
    public function confirm(Request $request): JsonResponse
    {
        $data = $request->validate([
            'sber_order_id' => ['required', 'string', 'max:64'],
        ]);

        $order = $this->orders->confirm($data['sber_order_id']);

        return response()->json(['success' => true, 'data' => $this->present($order)]);
    }

    public function show(string $id): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $this->present(Order::findOrFail($id))]);
    }

    private function present(Order $order): array
    {
        return [
            'order_id' => $order->id,
            'status' => $order->status,
            'from_name' => $order->from_name,
            'to_name' => $order->to_name,
            'trip_name' => $order->trip_name,
            'carrier' => $order->carrier,
            'date_start' => $order->date_start->format('Y-m-d'),
            'time_start' => substr((string) $order->time_start, 0, 5),
            'total_price' => (float) $order->total_price,
            'email' => $order->email,
            'back_code' => $order->back_code,
            'tickets' => collect($order->tickets ?? [])->map(fn (array $t) => [
                'place' => $t['place'],
                'type' => $t['type'],
                'serial' => $t['serial'] ?? null,
                'number' => $t['number'] ?? null,
                'last_name' => $t['last_name'],
                'first_name' => $t['first_name'],
                'middle_name' => $t['middle_name'] ?? '',
                'price' => (float) $t['price'],
                'baggage' => (int) ($t['baggage'] ?? 0),
                'price_bag' => (float) ($t['price_bag'] ?? 0),
                'baggage_tickets' => $t['baggage_tickets'] ?? [],
            ]),
        ];
    }
}
