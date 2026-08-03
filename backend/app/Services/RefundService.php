<?php

namespace App\Services;

use App\Jobs\RegisterRefundReceipt;
use App\Jobs\SendRefundEmail;
use App\Models\Order;
use App\Models\Refund;
use App\Models\Ticket;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Validation\ValidationException;

/**
 * Возврат билета по коду для возврата (перенос legacy url/backbycode.php).
 *
 * Код на билете = back_code заказа (15 символов) + номер билета.
 * Двухшаговый флоу: preview (расчёт удержаний, подписанный токен)
 * → confirm (аннулирование на станции + возврат платежа + чек + письмо).
 */
class RefundService
{
    /** Секунд до отправления, после которых возврат по коду закрыт (legacy: 1200). */
    private const REFUND_DEADLINE = 1200;

    /** Секунд на подтверждение условий возврата (legacy: 600). */
    private const CONFIRM_TTL = 600;

    public function __construct(
        private readonly SalesApiClient $salesApi,
        private readonly PaymentGateway $payments,
    ) {}

    /**
     * Шаг 1: рассчитать условия возврата, ничего не возвращая.
     *
     * @return array условия + подписанный токен для confirm()
     */
    public function preview(string $code): array
    {
        [$order, $ticket] = $this->resolveTicket($code);

        $canceledTrip = $this->isTripCanceled($order);

        $departureAt = strtotime($order->date_start->format('Y-m-d').' '.$order->time_start);

        if (! $canceledTrip && time() > $departureAt - self::REFUND_DEADLINE) {
            throw ValidationException::withMessages([
                'code' => 'Возврат билета по коду возможен не позднее чем за 30 мин до отправления автобуса. Обратитесь на автовокзал к диспетчеру.',
            ]);
        }

        $preview = $this->stationRefund($order, $ticket, preview: true);

        // Сорванный/отменённый рейс возвращается без удержаний (legacy-правило).
        $penalty = $canceledTrip ? 0 : (int) round((float) ($preview['penalties'][0] ?? 0));
        $amount = $this->refundAmount($ticket, $penalty);

        $payload = [
            'order_id' => $order->id,
            'serial' => (string) $ticket['serial'],
            'number' => (string) $ticket['number'],
            'penalty' => $penalty,
            'amount' => $amount,
            'expires_at' => time() + self::CONFIRM_TTL,
        ];

        return [
            'from_name' => $order->from_name,
            'to_name' => $order->to_name,
            'trip_name' => $preview['trip_name'] ?: $order->trip_name,
            'date_start' => $order->date_start->format('Y-m-d'),
            'time_start' => substr((string) $order->time_start, 0, 5),
            'place' => $ticket['place'],
            'penalty_percent' => $penalty,
            'amount' => $amount,
            'confirm_token' => Crypt::encrypt($payload),
            'confirm_ttl' => self::CONFIRM_TTL,
        ];
    }

    /**
     * Шаг 2: подтверждённый возврат — аннулирование билета на станции,
     * возврат платежа в банке, чек ККМ и письмо в очередь.
     */
    public function confirm(string $token): array
    {
        try {
            $payload = Crypt::decrypt($token);
        } catch (\Throwable) {
            throw ValidationException::withMessages(['code' => 'Потеряна целостность данных сессии']);
        }

        if (($payload['expires_at'] ?? 0) < time()) {
            throw ValidationException::withMessages(['code' => 'Время подтверждения операции истекло']);
        }

        $order = Order::findOrFail($payload['order_id']);
        $ticket = $order->tickets->first(
            fn (Ticket $t) => (int) $t->number === (int) $payload['number'],
        );

        if (! $ticket) {
            throw ValidationException::withMessages(['code' => 'Нет билетов с такими номерами в заказе']);
        }

        $this->assertNotRefunded($order, $ticket);

        $result = $this->stationRefund($order, $ticket, preview: false);

        if ((float) $result['total_refund'] <= 0) {
            throw new SalesApiException('Не удалось вернуть, ошибка API автовокзала', 'refund_failed');
        }

        $refund = Refund::create([
            'order_id' => $order->id,
            'serial' => (string) $ticket['serial'],
            'number' => (string) $ticket['number'],
            'place' => (int) $ticket['place'],
            'penalty_percent' => $payload['penalty'],
            'ticket_price' => (float) $ticket['price'],
            'baggage_price' => (int) ($ticket['baggage'] ?? 0) * (float) ($ticket['price_bag'] ?? 0),
            'amount' => $payload['amount'],
            'status' => Refund::STATUS_REFUNDED,
        ]);
        $order->touch();

        // Возврат платежа. При отказе банка билет уже аннулирован — деньги
        // вернёт бухгалтерия вручную (поведение legacy).
        $bankError = null;
        try {
            $this->payments->refund($order->sber_order_id, (int) floor($payload['amount'] * 100));
            $refund->update([
                'status' => Refund::STATUS_MONEY_REFUNDED,
                'money_refunded_at' => now(),
            ]);
            RegisterRefundReceipt::dispatch($refund->id)->onQueue('receipts');
        } catch (\Throwable $e) {
            $bankError = 'Не удалось вернуть платёж. Он будет возвращён в ближайшее время бухгалтерией';
            $refund->update([
                'status' => Refund::STATUS_BANK_FAILED,
                'bank_error' => $e->getMessage(),
            ]);
        }

        SendRefundEmail::dispatch($refund->id)->onQueue('emails');

        return [
            'from_name' => $order->from_name,
            'to_name' => $order->to_name,
            'trip_name' => $result['trip_name'] ?: $order->trip_name,
            'date_start' => $order->date_start->format('Y-m-d'),
            'time_start' => substr((string) $order->time_start, 0, 5),
            'place' => $ticket['place'],
            'amount' => (float) $payload['amount'],
            'bank_error' => $bankError,
        ];
    }

    /**
     * Разбор кода с билета и все проверки, общие для preview.
     *
     * @return array{0: Order, 1: Ticket} заказ и билет
     */
    private function resolveTicket(string $code): array
    {
        $code = preg_replace('/\s+/', '', $code);

        if (strlen($code) < 16) {
            throw ValidationException::withMessages(['code' => 'Не корректный код возврата']);
        }

        $order = Order::where('back_code', substr($code, 0, 15))->first();

        if (! $order) {
            throw ValidationException::withMessages(['code' => 'Информация по этому заказу не найдена']);
        }

        if ($order->status !== Order::STATUS_COMPLETED) {
            throw ValidationException::withMessages(['code' => 'Заказ не может быть возвращён: статус не позволяет']);
        }

        $number = substr($code, 15);
        $ticket = $order->tickets->first(
            fn (Ticket $t) => (int) $t->number === (int) $number,
        );

        if (! $ticket) {
            throw ValidationException::withMessages(['code' => 'Нет билетов с такими номерами в заказе']);
        }

        $this->assertNotRefunded($order, $ticket);

        return [$order, $ticket];
    }

    private function assertNotRefunded(Order $order, Ticket $ticket): void
    {
        $exists = Refund::where('order_id', $order->id)
            ->where('serial', (string) $ticket['serial'])
            ->where('number', (string) $ticket['number'])
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages(['code' => 'Билет уже возвращён']);
        }
    }

    /** Отменён или сорван ли рейс (тогда возврат без удержаний и без дедлайна). */
    private function isTripCanceled(Order $order): bool
    {
        $trip = $this->salesApi->tripStatus(
            $order->awtcod,
            $order->date_start->format('Y-m-d'),
            $order->napcod,
            $order->raicecod,
        )['data'];

        return (int) ($trip['CANCELED'] ?? 0) === 1 || (int) ($trip['DISRUPT'] ?? 0) === 1;
    }

    private function stationRefund(Order $order, Ticket $ticket, bool $preview): array
    {
        return $this->salesApi->refund([
            'awtcod' => $order->awtcod,
            'operation' => 1,
            'preview' => $preview,
            'tickets' => [[
                'serial' => (string) $ticket['serial'],
                'number' => (string) $ticket['number'],
            ]],
        ])['data'];
    }

    /**
     * Сумма к возврату: (билет + багаж) минус процент удержаний,
     * с округлением вниз до копеек (формула legacy backbycode.php).
     */
    private function refundAmount(Ticket $ticket, int $penalty): float
    {
        $gross = (float) $ticket['price']
            + (int) ($ticket['baggage'] ?? 0) * (float) ($ticket['price_bag'] ?? 0);

        return floor($gross * (1 - $penalty / 100) * 100) / 100;
    }
}
