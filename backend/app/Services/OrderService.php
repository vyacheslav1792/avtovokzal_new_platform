<?php

namespace App\Services;

use App\Jobs\RegisterFiscalReceipt;
use App\Jobs\SendPurchaseEmail;
use App\Models\Order;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class OrderService
{
    public function __construct(
        private readonly SalesApiClient $salesApi,
        private readonly PaymentGateway $payments,
    ) {}

    /**
     * Создать заказ: резерв мест в API продаж + регистрация оплаты в шлюзе.
     *
     * @return Order с payment_url для редиректа на шлюз
     */
    public function create(array $data, string $ip): Order
    {
        // Цены берём НЕ из запроса, а из API продаж — защита от подмены.
        $trip = $this->findTrip($data);

        if (! $trip) {
            throw ValidationException::withMessages(['trip' => 'Рейс не найден или продажа на него закрыта']);
        }

        $departureAt = strtotime($trip['date_start'].' '.$trip['time_start']);
        if ($departureAt < time() + 1920) {
            throw ValidationException::withMessages([
                'trip' => 'Покупка билетов через сайт возможна не позднее чем за 30 минут до отправления автобуса',
            ]);
        }

        $adults = collect($data['passengers'])->filter(
            fn (array $p) => $this->tariffType($p) === 'П',
        )->count();
        $children = count($data['passengers']) - $adults;
        if ($adults === 0 || $children > $adults * 2) {
            throw ValidationException::withMessages([
                'passengers' => 'Детские билеты приобретаются только в связке с полным: не более 2 детей на одного взрослого',
            ]);
        }

        $reservation = $this->salesApi->reserve([
            'awtcod' => $data['awtcod'],
            'napcod' => $data['napcod'],
            'raicecod' => $data['raicecod'],
            'statcod' => $data['statcod'],
            'date' => $data['date'],
            'places' => collect($data['passengers'])->pluck('place')->map(fn ($p) => (int) $p)->all(),
        ])['data'];

        $passengers = [];
        $total = 0.0;
        foreach (array_values($data['passengers']) as $i => $p) {
            $type = $this->tariffType($p);
            $price = $type === 'Д' ? $trip['price_child'] : $trip['price_full'];
            $baggage = (int) ($p['baggage'] ?? 0);

            $passengers[] = [
                'place' => $reservation['places'][$i] ?? $p['place'],
                'type' => $type,
                'price' => $price,
                'last_name' => mb_strtoupper($p['last_name']),
                'first_name' => mb_strtoupper($p['first_name']),
                'middle_name' => mb_strtoupper($p['middle_name'] ?? ''),
                'birth_date' => $p['birth_date'],
                'gender' => $p['gender'] ?? 'М',
                'doc_type' => $this->docTypeCode($p),
                'doc_series' => $p['doc_series'] ?? '',
                'doc_number' => $p['doc_number'],
                'citizenship' => (int) ($p['citizenship'] ?? 185),
                'baggage' => $baggage,
                'price_bag' => $baggage > 0 ? $trip['price_baggage'] : 0,
                'comment' => $data['phone'] ?? '',
            ];

            $total += $price + $baggage * $trip['price_baggage'];
        }

        $order = Order::create([
            'awtcod' => $data['awtcod'],
            'napcod' => $data['napcod'],
            'raicecod' => $data['raicecod'],
            'statcod' => $data['statcod'],
            'from_name' => $trip['awtname'] ?? (string) $data['awtcod'],
            'to_name' => $data['to_name'],
            'trip_name' => $trip['trip_name'].'–'.$trip['direction_name'],
            'carrier' => $trip['carrier'],
            'date_start' => $data['date'],
            'time_start' => $trip['time_start'],
            'arrival_at' => $this->parseArrival($reservation['datetime_arrival'] ?? null),
            'reservation_id' => $reservation['reservation_id'],
            'id_time_places' => $reservation['id_time_places'],
            'passengers' => $passengers,
            'total_price' => $total,
            'email' => mb_strtolower($data['email']),
            'phone' => $data['phone'] ?? null,
            'ip' => $ip,
            'status' => Order::STATUS_PENDING,
        ]);

        $payment = $this->payments->register(
            str_replace('-', '', $order->id), // шлюз не принимает UUID с дефисами
            (int) floor($total * 100),
            $this->paymentDescription($order),
            $this->normalizePhone($data['phone'] ?? ''),
        );

        $order->update([
            'sber_order_id' => $payment['orderId'],
            'payment_url' => $payment['formUrl'],
            'status' => Order::STATUS_PAYMENT_REGISTERED,
        ]);

        return $order;
    }

    /**
     * Подтверждение после возврата со шлюза: проверка оплаты,
     * оформление билетов, постановка чека и письма в очередь.
     */
    public function confirm(string $sberOrderId): Order
    {
        $order = Order::where('sber_order_id', $sberOrderId)->firstOrFail();

        if ($order->status === Order::STATUS_COMPLETED) {
            return $order; // идемпотентность: повторный заход на страницу
        }

        if (! in_array($order->status, [Order::STATUS_PAYMENT_REGISTERED, Order::STATUS_PAID], true)) {
            throw new SalesApiException('Заказ имеет недопустимый статус и не может быть подтверждён', 'bad_order_status');
        }

        if ($order->status !== Order::STATUS_PAID) {
            if (! $this->payments->isPaid($sberOrderId)) {
                throw new SalesApiException('Заказ не оплачен', 'not_paid');
            }
            $order->update(['status' => Order::STATUS_PAID, 'paid_at' => now()]);
        }

        try {
            $sale = $this->salesApi->sell([
                'reservation_id' => $order->reservation_id,
                'tickets' => $order->passengers,
            ])['data'];
        } catch (SalesApiException $e) {
            $order->update([
                'status' => Order::STATUS_FAILED,
                'status_reason' => $e->getMessage(),
            ]);
            throw $e;
        }

        $order->update([
            'tickets' => $sale['tickets'],
            'back_code' => $this->generateBackCode(),
            'status' => Order::STATUS_COMPLETED,
            'completed_at' => now(),
        ]);

        // Поток чека и письма — в очередь; обработчик пока не запускаем.
        RegisterFiscalReceipt::dispatch($order->id)->onQueue('receipts');
        SendPurchaseEmail::dispatch($order->id)->onQueue('emails');

        return $order->refresh();
    }

    private function findTrip(array $data): ?array
    {
        $trips = $this->salesApi->trips(
            (int) $data['awtcod'],
            $data['to_name'],
            $data['date'],
        );

        foreach ($trips['data'] as $trip) {
            if ((int) $trip['napcod'] === (int) $data['napcod']
                && (int) $trip['raicecod'] === (int) $data['raicecod']) {
                return $trip + ['awtname' => $trips['awtname'] ?? null];
            }
        }

        return null;
    }

    /** Детский тариф — до 12 лет (legacy det_age). */
    private function tariffType(array $passenger): string
    {
        if (($passenger['tariff'] ?? 'adult') !== 'child') {
            return 'П';
        }

        $age = (int) date_create($passenger['birth_date'])->diff(date_create('now'))->y;

        return $age < 12 ? 'Д' : 'П';
    }

    /**
     * Код документа BUSTER + разбор серии/номера. Формат и совместимость
     * с гражданством уже проверены в StoreOrderRequest по правилам
     * станции (см. PassengerDocument) — реальный код документа сохраняем.
     */
    private function docTypeCode(array &$passenger): int
    {
        $code = (int) ($passenger['doc_type'] ?? 21);

        $parsed = PassengerDocument::parse($code, ($passenger['doc_series'] ?? '').$passenger['doc_number']);

        $passenger['doc_series'] = $parsed['series'];
        $passenger['doc_number'] = $parsed['number'];

        return $code;
    }

    private function paymentDescription(Order $order): string
    {
        $lines = [];
        foreach ($order->passengers as $p) {
            $bag = '; багаж: '.($p['baggage'] ?? 0).' шт.';
            $lines[] = sprintf(
                'Билет %s – %s, %s в %s, м. %s%s',
                $order->from_name,
                $order->to_name,
                $order->date_start->translatedFormat('d F Y'),
                substr((string) $order->time_start, 0, 5),
                $p['place'],
                $bag,
            );
        }

        return implode("\r\n", $lines);
    }

    /** «03.08.2026 15:00» (формат BUSTER) → datetime или null. */
    private function parseArrival(?string $value): ?\DateTimeInterface
    {
        if (! $value) {
            return null;
        }

        return \DateTime::createFromFormat('d.m.Y H:i', trim($value)) ?: null;
    }

    private function normalizePhone(string $phone): ?string
    {
        $digits = preg_replace('/\D+/', '', $phone);

        if (strlen($digits) === 11 && $digits[0] === '8') {
            $digits = '7'.substr($digits, 1);
        } elseif (strlen($digits) === 10 && $digits[0] === '9') {
            $digits = '7'.$digits;
        }

        return strlen($digits) === 11 ? $digits : null;
    }

    private function generateBackCode(): string
    {
        return str_pad(substr((string) (time() - 1567026000), 4, 6), 6, '0')
            .random_int(100000000, 999999999);
    }
}
