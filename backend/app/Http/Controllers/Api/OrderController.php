<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\OrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\HeaderUtils;

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

    /** Секунд после отправления, через которые билеты обезличиваются (legacy obezlich_time). */
    private const DEPERSONALIZE_AFTER = 3600 * 24 * 30;

    /**
     * Данные для печатной формы билетов (страница /print/{id},
     * перенос legacy print.php). Доступ — по UUID заказа, как в legacy
     * по 32-символьному ORDER_ID.
     */
    public function printData(string $id): JsonResponse
    {
        $order = Order::where('status', Order::STATUS_COMPLETED)->findOrFail($id);

        $departureAt = strtotime($order->date_start->format('Y-m-d').' '.$order->time_start);
        $masked = time() - $departureAt > self::DEPERSONALIZE_AFTER;

        $citizenships = \App\Models\Citizenship::query()
            ->pluck('name', 'code');

        $tickets = $order->tickets->map(function (\App\Models\Ticket $t) use ($masked, $citizenships) {
            $gender = $t['gender'] ?? 'М';

            return [
                'serial' => $t['serial'] ?? '',
                'number' => $t['number'] ?? '',
                'place' => $t['place'],
                'tariff' => ($t['type'] ?? 'П') === 'Д' ? 'Детский' : 'Полный',
                'price' => (float) $t['price'],
                'baggage' => (int) ($t['baggage'] ?? 0),
                'price_bag' => (float) ($t['price_bag'] ?? 0),
                'baggage_tickets' => $t['baggage_tickets'] ?? [],
                'passenger' => $masked
                    ? '-обезличено-'
                    : trim($t['last_name'].' '.$t['first_name'].' '.($t['middle_name'] ?? '')),
                'citizenship' => $citizenships[(int) ($t['citizenship'] ?? 185)] ?? '',
                'doc_name' => config('documents.'.(int) ($t['doc_type'] ?? 21), 'Паспорт'),
                'doc_number' => $masked
                    ? '-***-'
                    : trim(($t['doc_series'] ?? '').' '.$t['doc_number']),
                'birth_date' => $masked
                    ? '-обезличено-'
                    : date('d.m.Y', strtotime($t['birth_date'])),
                'gender' => $gender === 'Ж' ? 'Женский' : 'Мужской',
            ];
        });

        return response()->json(['success' => true, 'data' => [
            'order_id' => $order->id,
            'from_name' => $order->from_name,
            'to_name' => $order->to_name,
            'trip_name' => $order->trip_name,
            'carrier' => $order->carrier,
            'date_start' => $order->date_start->format('Y-m-d'),
            'time_start' => substr((string) $order->time_start, 0, 5),
            'date_end' => $order->arrival_at?->timezone(config('app.timezone'))->format('Y-m-d'),
            'time_end' => $order->arrival_at?->timezone(config('app.timezone'))->format('H:i'),
            'sold_at' => $order->completed_at?->timezone(config('app.timezone'))->format('d.m.Y H:i'),
            'back_code' => $order->back_code,
            'fiscal_sign' => $order->fiscal_sign,
            'total_price' => (float) $order->total_price,
            'depersonalized' => $masked,
            'tickets' => $tickets,
        ]]);
    }

    /**
     * Билеты одним PDF (перенос legacy `pdf.php`): Gotenberg рендерит ту же
     * печатную форму `/print/{id}`, файл отдаётся вложением с именем
     * «Билеты на дд.мм.гггг.pdf» — как в legacy. Кэш на диске не держим:
     * рендер занимает секунды, а данные формы меняются (обезличивание,
     * возвраты).
     */
    public function pdf(string $id, \App\Services\PdfRenderer $renderer): Response
    {
        $order = Order::where('status', Order::STATUS_COMPLETED)->findOrFail($id);

        $pdf = $renderer->fromUrl(
            rtrim(config('services.gotenberg.print_base'), '/').'/print/'.$order->id,
        );

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => HeaderUtils::makeDisposition(
                HeaderUtils::DISPOSITION_ATTACHMENT,
                'Билеты на '.$order->date_start->format('d.m.Y').'.pdf',
                'tickets-'.$order->date_start->format('d.m.Y').'.pdf',
            ),
        ]);
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
            'tickets' => $order->tickets->map(fn (\App\Models\Ticket $t) => [
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
