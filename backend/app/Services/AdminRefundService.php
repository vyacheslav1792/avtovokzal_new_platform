<?php

namespace App\Services;

use App\Jobs\RegisterRefundReceipt;
use App\Jobs\SendRefundEmail;
use App\Models\Order;
use App\Models\Refund;
use App\Models\Ticket;
use Illuminate\Validation\ValidationException;

/**
 * Админский возврат билета — перенос legacy ais/back_bilet.php.
 *
 * Отличия от возврата по коду (RefundService):
 *  - доступ по order_id + serial/number, БЕЗ дедлайна «за 30 минут» и после
 *    отправления рейса (admin_mode в API продаж, legacy: IS_PRN && !admin_mode);
 *  - сверка оплаты со шлюзом (getOrderStatusExtended) до возврата;
 *  - процент удержаний выбирает админ: 0/5/15/25, станция даёт рекомендуемый;
 *  - починка зависшего возврата: деньги не ушли в банк — повторить платёж.
 *
 * Сознательные отличия от legacy (см. CLAUDE.md «Правила»):
 *  - защитный код 123321 не переносим (позже — нормальная авторизация);
 *  - выбора учётки шлюза по эпохе даты заказа (setSbrf/new_gateway_date) нет:
 *    в новой БД только заказы одного мерчанта, legacy-заказы не мигрированы;
 *  - при починке денег пробиваем чек возврата: в legacy ветка чека была
 *    недостижима (need_fiskal ставился, но блок чека — в другой ветке), а
 *    54-ФЗ чек требует; RegisterRefundReceipt идемпотентен.
 *
 * Legacy-«особенность», которую НЕ воспроизводим буквально: обнуление штрафа
 * за сорванный рейс в back_bilet было мёртвым кодом (проверялся
 * неинициализированный $otvet1), то есть фактически не работало — поэтому
 * здесь его тоже нет, админ сам ставит 0%.
 */
class AdminRefundService
{
    /** Варианты процента удержаний из формы legacy back_bilet.php. */
    public const PERCENTS = [0, 5, 15, 25];

    /** Расшифровки orderStatus шлюза (карта legacy back_bilet.php). */
    private const ORDER_STATUS_TEXT = [
        0 => 'не оплачен',
        1 => 'Предавторизованная сумма захолдирована (для двухстадийных платежей)',
        2 => 'Успешно оплачен',
        3 => 'Отменён',
        4 => 'По транзакции была проведена операция возврата',
        5 => 'Инициирована авторизация через ACS банка-эмитента',
        6 => 'Заказ отклонён',
    ];

    public function __construct(
        private readonly SalesApiClient $salesApi,
        private readonly PaymentGateway $payments,
    ) {}

    /**
     * Карточка возврата: заказ, сверка со шлюзом, пассажиры, состояние
     * билета (можно вернуть / починить деньги / нет чека / уже возвращён).
     */
    public function inspect(string $orderId, ?string $serial, ?string $number): array
    {
        $order = $this->findOrder($orderId);

        $result = [
            'order' => $this->orderInfo($order),
            'bank' => $this->bankInfo($order),
            'passengers' => $this->passengersInfo($order),
        ];

        // Как legacy: без номера билета показываем только информацию по заказу.
        if (! $serial || ! $number) {
            return $result + [
                'state' => 'no_ticket',
                'message' => 'Не передан номер билета к возврату',
            ];
        }

        $ticket = $this->findTicket($order, $serial, $number);
        $result['ticket'] = $this->ticketInfo($ticket);

        $refund = $this->findRefund($order, $ticket);

        if ($refund) {
            return $result + $this->refundState($refund);
        }

        // Предпросмотр возврата на станции (ONLY_SHOW, admin_mode).
        $preview = $this->stationRefund($order, $ticket, preview: true);
        $recommended = (int) round((float) ($preview['penalties'][0] ?? 0));

        return $result + [
            'state' => 'refundable',
            'trip_name' => $preview['trip_name'] ?: $order->trip_name,
            'datetime_start' => $preview['datetime_start'],
            'recommended_percent' => $recommended,
            // Суммы к возврату по каждому варианту процента (формула legacy).
            'variants' => array_map(fn (int $p) => [
                'percent' => $p,
                'amount' => $this->refundAmount($ticket, $p),
            ], self::PERCENTS),
        ];
    }

    /**
     * Выполнить возврат с выбранным админом процентом удержаний.
     * Вендорная ведомость считает свой штраф сама (в legacy параметр shtraf
     * до процедуры KS_ANN_TICKET_FROM_AW не доходил) — процент админа
     * влияет только на сумму, возвращаемую покупателю на карту.
     */
    public function confirm(string $orderId, string $serial, string $number, int $percent): array
    {
        // Legacy: PROC<0 → «Не выбран процент удержаний!»; варианты — из формы.
        if (! in_array($percent, self::PERCENTS, true)) {
            throw ValidationException::withMessages(['percent' => 'Не выбран процент удержаний!']);
        }

        $order = $this->findOrder($orderId);
        $ticket = $this->findTicket($order, $serial, $number);

        if ($this->findRefund($order, $ticket)) {
            throw ValidationException::withMessages(['ticket' => 'Билет уже возвращён']);
        }

        // Аннулирование в ведомости станции (back=true, admin_mode).
        $result = $this->stationRefund($order, $ticket, preview: false);

        if ((float) $result['total_refund'] <= 0) {
            throw new SalesApiException('Не удалось вернуть, ошибка API автовокзала', 'refund_failed');
        }

        $amount = $this->refundAmount($ticket, $percent);

        $refund = Refund::create([
            'order_id' => $order->id,
            'serial' => (string) $ticket->serial,
            'number' => (string) $ticket->number,
            'place' => (int) $ticket->place,
            'penalty_percent' => $percent,
            'ticket_price' => (float) $ticket->price,
            'baggage_price' => (int) $ticket->baggage * (float) $ticket->price_bag,
            'amount' => $amount,
            'status' => Refund::STATUS_REFUNDED,
        ]);
        $order->touch();

        $bankError = $this->refundMoney($refund, $order);

        // Письмо о возврате уходит независимо от исхода банка (как legacy).
        SendRefundEmail::dispatch($refund->id)->onQueue('emails');

        return [
            'trip_name' => $result['trip_name'] ?: $order->trip_name,
            'datetime_start' => $result['datetime_start'],
            'amount' => $amount,
            'bank_error' => $bankError,
        ];
    }

    /**
     * Починка зависшего возврата: билет аннулирован, а деньги в банк не ушли
     * (legacy-ветка «Не прошёл возврат денег по банку»).
     */
    public function repairMoney(string $orderId, string $serial, string $number): array
    {
        $order = $this->findOrder($orderId);
        $ticket = $this->findTicket($order, $serial, $number);
        $refund = $this->findRefund($order, $ticket);

        if (! $refund) {
            throw ValidationException::withMessages(['ticket' => 'Возврат этого билета не найден']);
        }
        // Legacy-условие: DATE_MONEY_BACK пуст и SUMM_BACK есть.
        if ($refund->money_refunded_at || (float) $refund->amount <= 0) {
            throw ValidationException::withMessages(['ticket' => 'Возврат денег по этому билету уже прошёл']);
        }

        $bankError = $this->refundMoney($refund, $order);

        return [
            'amount' => (float) $refund->amount,
            'bank_error' => $bankError,
        ];
    }

    /**
     * Возврат платежа в шлюзе + чек. При отказе банка возврат остаётся
     * bank_failed — деньги вернёт бухгалтерия (текст legacy).
     */
    private function refundMoney(Refund $refund, Order $order): ?string
    {
        try {
            $this->payments->refund($order->sber_order_id, (int) floor((float) $refund->amount * 100));
            $refund->update([
                'status' => Refund::STATUS_MONEY_REFUNDED,
                'money_refunded_at' => now(),
                'bank_error' => null,
            ]);
            RegisterRefundReceipt::dispatch($refund->id)->onQueue('receipts');

            return null;
        } catch (\Throwable $e) {
            $refund->update([
                'status' => Refund::STATUS_BANK_FAILED,
                'bank_error' => $e->getMessage(),
            ]);

            return 'Не удалось вернуть платёж. Он будет возвращён в ближайшее время бухгалтерией';
        }
    }

    /**
     * Сумма к возврату на карту: (билет + багаж) минус выбранный процент.
     * Формула — точно как в подтверждении legacy back_bilet.php:
     * dobrstr + number_format(gross,2)*number_format(1-p/100,2), итог —
     * банковское округление number_format (НЕ floor, как в backbycode).
     * Страховка (dobrstr) в legacy возвращалась полностью, без удержаний;
     * новый сайт страховку не продаёт — слагаемое равно нулю.
     */
    private function refundAmount(Ticket $ticket, int $percent): float
    {
        $gross = (float) $ticket->price + (int) $ticket->baggage * (float) $ticket->price_bag;

        return round(
            (float) number_format($gross, 2, '.', '') * (float) number_format(1 - $percent / 100, 2, '.', ''),
            2,
        );
    }

    private function findOrder(string $orderId): Order
    {
        $order = Order::find($orderId);

        if (! $order) {
            throw ValidationException::withMessages(['order_id' => 'Информация по этому заказу не найдена в БД']);
        }

        return $order;
    }

    private function findTicket(Order $order, string $serial, string $number): Ticket
    {
        $ticket = $order->tickets->first(
            fn (Ticket $t) => (string) $t->serial === $serial && (string) $t->number === $number,
        );

        if (! $ticket) {
            throw ValidationException::withMessages(['ticket' => 'Нет билетов с такими номерами в заказе']);
        }

        return $ticket;
    }

    private function findRefund(Order $order, Ticket $ticket): ?Refund
    {
        return Refund::where('order_id', $order->id)
            ->where('serial', (string) $ticket->serial)
            ->where('number', (string) $ticket->number)
            ->first();
    }

    /** Состояние существующего возврата (ветки «Проверка возврата» legacy). */
    private function refundState(Refund $refund): array
    {
        if (! $refund->money_refunded_at && (float) $refund->amount > 0) {
            return [
                'state' => 'repair_money',
                'message' => 'Не прошёл возврат денег по банку',
                'refund' => $this->refundInfo($refund),
            ];
        }

        if (! $refund->fiscal_sign) {
            return [
                'state' => 'no_receipt',
                'message' => 'Нет фискального чека',
                'refund' => $this->refundInfo($refund),
            ];
        }

        return [
            'state' => 'already_refunded',
            'message' => 'Билет уже возвращён успешно ранее. Платёж возвращён, фискальный чек есть',
            'refund' => $this->refundInfo($refund),
        ];
    }

    private function orderInfo(Order $order): array
    {
        return [
            'id' => $order->id,
            'status' => $order->status,
            'from_name' => $order->from_name,
            'to_name' => $order->to_name,
            'trip_name' => $order->trip_name,
            'date_start' => $order->date_start->format('Y-m-d'),
            'time_start' => substr((string) $order->time_start, 0, 5),
            'email' => $order->email,
            'phone' => $order->phone,
            'total_price' => (float) $order->total_price,
            'sber_order_id' => $order->sber_order_id,
            // Legacy показывал пароль ЛК (PASSWORD_RAW) — пользователи/ЛК
            // в новый стек ещё не перенесены, показывать нечего.
            'lk_password' => null,
        ];
    }

    /** Сверка оплаты со шлюзом (legacy: getOrderStatusExtended + расшифровка). */
    private function bankInfo(Order $order): array
    {
        if (! $order->sber_order_id) {
            return ['error' => 'Заказ не был зарегистрирован в платёжном шлюзе'];
        }

        try {
            $status = $this->payments->statusExtended($order->sber_order_id);
        } catch (\Throwable $e) {
            return ['error' => 'Ошибка взаимодействия со сбербанком'.$e->getMessage()];
        }

        if (! empty($status['errorCode'])) {
            return ['error' => 'Ошибка взаимодействия со сбербанком'.($status['errorMessage'] ?? '')];
        }
        if (empty($status['amount'])) {
            return ['error' => 'Ошибка взаимодействия со сбербанком странный ответ'.($status['errorMessage'] ?? '')];
        }

        $code = (int) ($status['orderStatus'] ?? -1);

        return [
            'order_status' => $code,
            'status_text' => self::ORDER_STATUS_TEXT[$code] ?? (string) $code,
            'action_code_description' => $status['actionCodeDescription'] ?? '',
            'amount' => ((int) $status['amount']) / 100,
            'refunded' => ((int) ($status['paymentAmountInfo']['refundedAmount'] ?? 0)) / 100,
        ];
    }

    private function passengersInfo(Order $order): array
    {
        return array_map(fn (array $p) => [
            'name' => trim(($p['last_name'] ?? '').' '.($p['first_name'] ?? '').' '.($p['middle_name'] ?? '')),
            'citizenship' => $p['citizenship'] ?? null,
            'birth_date' => $p['birth_date'] ?? null,
            'document' => trim(($p['doc_series'] ?? '').' '.($p['doc_number'] ?? '')),
        ], $order->passengers ?? []);
    }

    private function ticketInfo(Ticket $ticket): array
    {
        return [
            'serial' => $ticket->serial,
            'number' => $ticket->number,
            'place' => (int) $ticket->place,
            'type' => $ticket->type,
            'price' => (float) $ticket->price,
            'baggage' => (int) $ticket->baggage,
            'price_bag' => (float) $ticket->price_bag,
            'passenger' => trim($ticket->last_name.' '.$ticket->first_name.' '.($ticket->middle_name ?? '')),
        ];
    }

    private function refundInfo(Refund $refund): array
    {
        return [
            'amount' => (float) $refund->amount,
            'penalty_percent' => (int) $refund->penalty_percent,
            'status' => $refund->status,
            'refunded_at' => $refund->created_at?->format('Y-m-d H:i:s'),
            'money_refunded_at' => $refund->money_refunded_at?->format('Y-m-d H:i:s'),
            'fiscal_sign' => $refund->fiscal_sign,
            'bank_error' => $refund->bank_error,
        ];
    }

    private function stationRefund(Order $order, Ticket $ticket, bool $preview): array
    {
        return $this->salesApi->refund([
            'awtcod' => $order->awtcod,
            'operation' => 1,
            'preview' => $preview,
            'admin_mode' => true,
            'tickets' => [[
                'serial' => (string) $ticket->serial,
                'number' => (string) $ticket->number,
            ]],
        ])['data'];
    }
}
