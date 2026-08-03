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
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'awtcod' => ['required', 'integer'],
            'napcod' => ['required', 'integer'],
            'raicecod' => ['required', 'integer'],
            'statcod' => ['required', 'integer'],
            'date' => ['required', 'date_format:Y-m-d'],
            'to_name' => ['required', 'string', 'max:128'],
            'email' => ['required', 'email', 'max:255'],
            'phone' => ['required', 'string', 'max:32'],
            'agree_pd' => ['accepted'],
            'passengers' => ['required', 'array', 'min:1', 'max:10'],
            'passengers.*.place' => ['required', 'integer', 'min:1'],
            'passengers.*.tariff' => ['sometimes', 'in:adult,child'],
            'passengers.*.last_name' => ['required', 'string', 'max:40'],
            'passengers.*.first_name' => ['required', 'string', 'max:30'],
            'passengers.*.middle_name' => ['nullable', 'string', 'max:30'],
            'passengers.*.birth_date' => ['required', 'date', 'before:today'],
            'passengers.*.gender' => ['sometimes', 'in:М,Ж'],
            'passengers.*.doc_type' => ['sometimes', 'integer'],
            'passengers.*.doc_series' => ['nullable', 'string', 'max:10'],
            'passengers.*.doc_number' => ['required', 'string', 'max:25'],
            'passengers.*.citizenship' => ['sometimes', 'integer'],
            'passengers.*.baggage' => ['sometimes', 'integer', 'min:0', 'max:5'],
        ]);

        $order = $this->orders->create($data, (string) $request->ip());

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
