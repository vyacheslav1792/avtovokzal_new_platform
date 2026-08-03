<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Refund;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * Личный кабинет: заказы покупателя и их представление
 * (перенос legacy url/lk.php — со статусами, суммами возвратов
 * и разделением на предстоящие/прошедшие поездки).
 */
class CabinetService
{
    /** Сколько минут после создания заказа ссылка на оплату ещё имеет смысл. */
    private const PAYMENT_RESUME_MINUTES = 20;

    /**
     * Заказы пользователя: свои и купленные на его e-mail до регистрации.
     *
     * @param  'upcoming'|'past'|'all'  $scope
     */
    public function orders(User $user, string $scope = 'all', int $perPage = 20): LengthAwarePaginator
    {
        $query = $user->orders()
            ->with(['tickets', 'refunds'])
            // Незавершённые черновики без оплаты в кабинете не показываем:
            // заказ появляется, когда покупатель хотя бы дошёл до шлюза.
            ->whereIn('status', [
                Order::STATUS_PAYMENT_REGISTERED,
                Order::STATUS_PAID,
                Order::STATUS_COMPLETED,
                Order::STATUS_FAILED,
            ]);

        // Предстоящая поездка — по моменту отправления, а не по дате: утренний
        // рейс сегодняшнего дня уже прошёл. Время рейса BUSTER — wall-clock
        // московское, приложение живёт в той же зоне (config/app.timezone).
        if ($scope === 'upcoming') {
            $query->whereRaw('(date_start + time_start) >= ?', [now()->format('Y-m-d H:i:s')]);
        } elseif ($scope === 'past') {
            $query->whereRaw('(date_start + time_start) < ?', [now()->format('Y-m-d H:i:s')]);
        }

        $orders = $query
            ->orderByDesc('date_start')
            ->orderByDesc('created_at')
            ->paginate($perPage);

        $orders->getCollection()->transform(fn (Order $order) => $this->present($order));

        return $orders;
    }

    /** Один заказ пользователя (404, если чужой). */
    public function order(User $user, string $orderId): array
    {
        return $this->present($this->findOrder($user, $orderId));
    }

    /** Заказ пользователя как модель — для возврата билета. */
    public function findOrder(User $user, string $orderId): Order
    {
        $order = $user->orders()->with(['tickets', 'refunds'])->find($orderId);

        if (! $order) {
            throw new ModelNotFoundException('Заказ не найден');
        }

        return $order;
    }

    /** Билет заказа по id (для возврата из кабинета). */
    public function findTicket(Order $order, string $ticketId): Ticket
    {
        $ticket = $order->tickets->firstWhere('id', $ticketId);

        if (! $ticket) {
            throw new ModelNotFoundException('Билет не найден в заказе');
        }

        return $ticket;
    }

    /** Сводка для шапки кабинета. */
    public function summary(User $user): array
    {
        $completed = $user->orders()->where('status', Order::STATUS_COMPLETED);

        return [
            'orders_total' => (clone $completed)->count(),
            'upcoming_total' => $completed
                ->whereRaw('(date_start + time_start) >= ?', [now()->format('Y-m-d H:i:s')])
                ->count(),
        ];
    }

    private function present(Order $order): array
    {
        $departureAt = strtotime($order->date_start->format('Y-m-d').' '.$order->time_start);
        $refunds = $order->refunds->keyBy(fn (Refund $r) => $r->serial.':'.$r->number);

        $tickets = $order->tickets->map(function (Ticket $ticket) use ($order, $refunds, $departureAt) {
            $refund = $refunds->get($ticket->serial.':'.$ticket->number);

            return [
                'id' => $ticket->id,
                'serial' => $ticket->serial,
                'number' => $ticket->number,
                'place' => $ticket->place,
                'tariff' => $ticket->type === 'Д' ? 'Детский' : 'Полный',
                'passenger' => trim($ticket->last_name.' '.$ticket->first_name.' '.($ticket->middle_name ?? '')),
                'price' => (float) $ticket->price,
                'baggage' => $ticket->baggage,
                'price_bag' => (float) $ticket->price_bag,
                'total' => (float) $ticket->price + $ticket->baggage * (float) $ticket->price_bag,
                // Код с билета: back_code заказа + номер билета — им же
                // возвращают без входа в кабинет (страница /backbycode).
                'refund_code' => $order->back_code ? $order->back_code.$ticket->number : null,
                'refund' => $refund ? [
                    'amount' => (float) $refund->amount,
                    'penalty_percent' => (int) $refund->penalty_percent,
                    'status' => $refund->status,
                    'money_refunded' => $refund->status !== Refund::STATUS_BANK_FAILED,
                    'created_at' => $refund->created_at?->timezone(config('app.timezone'))->format('d.m.Y H:i'),
                ] : null,
                // Кнопку возврата прячем только у уже возвращённых: у сорванных
                // и отменённых рейсов возврат открыт и после дедлайна, а это
                // знает только API продаж (проверка — на шаге preview).
                'can_refund' => ! $refund && $order->status === Order::STATUS_COMPLETED,
                'refund_deadline_passed' => time() > $departureAt - RefundService::REFUND_DEADLINE,
            ];
        });

        $refundedTotal = $order->refunds->sum(fn (Refund $r) => (float) $r->amount);

        return [
            'id' => $order->id,
            'status' => $order->status,
            'status_label' => $this->statusLabel($order),
            'created_at' => $order->created_at?->timezone(config('app.timezone'))->format('d.m.Y H:i'),
            'from_name' => $order->from_name,
            'to_name' => $order->to_name,
            'trip_name' => $order->trip_name,
            'carrier' => $order->carrier,
            'date_start' => $order->date_start->format('Y-m-d'),
            'time_start' => substr((string) $order->time_start, 0, 5),
            'arrival_at' => $order->arrival_at?->timezone(config('app.timezone'))->format('d.m.Y H:i'),
            'total_price' => (float) $order->total_price,
            'refunded_total' => round($refundedTotal, 2),
            'back_code' => $order->back_code,
            'email' => $order->email,
            'phone' => $order->phone,
            'is_upcoming' => $departureAt > time(),
            'departed' => $departureAt <= time(),
            // «Продолжить покупку» из legacy lk.php: у неоплаченного заказа
            // ссылка на форму шлюза живёт минуты, дальше её показывать вредно.
            'payment_url' => $this->resumablePaymentUrl($order),
            'tickets' => $tickets,
        ];
    }

    private function resumablePaymentUrl(Order $order): ?string
    {
        if ($order->status !== Order::STATUS_PAYMENT_REGISTERED || ! $order->payment_url) {
            return null;
        }

        $expired = $order->created_at?->addMinutes(self::PAYMENT_RESUME_MINUTES)->isPast() ?? true;

        return $expired ? null : $order->payment_url;
    }

    private function statusLabel(Order $order): string
    {
        return match ($order->status) {
            Order::STATUS_COMPLETED => $order->tickets->isNotEmpty()
                && $order->refunds->count() >= $order->tickets->count()
                    ? 'Билеты возвращены'
                    : 'Оплачен',
            Order::STATUS_PAID => 'Оплачен, билеты оформляются',
            Order::STATUS_PAYMENT_REGISTERED => 'Ожидает оплаты',
            Order::STATUS_FAILED => 'Не оформлен',
            default => 'Черновик',
        };
    }
}
