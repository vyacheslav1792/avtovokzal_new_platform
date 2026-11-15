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
            // Все возвраты заказа: по ним видно, что уже вернули и где
            // осталось неотданное покупателю удержание.
            'refunds' => $this->orderRefunds($order),
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
        // Багажные квитанции билета: их можно вернуть отдельно, не трогая
        // сам билет (на станции это самостоятельные бланки).
        $result['baggage'] = $this->baggageInfo($order, $ticket);

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
                'amount' => $this->refundAmount($order, $ticket, $p),
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

        $amount = $this->refundAmount($order, $ticket, $percent);

        $refund = Refund::create([
            'order_id' => $order->id,
            'serial' => (string) $ticket->serial,
            'number' => (string) $ticket->number,
            'place' => (int) $ticket->place,
            'penalty_percent' => $percent,
            'ticket_price' => (float) $ticket->price,
            'baggage_price' => $this->baggageToRefund($order, $ticket) * (float) $ticket->price_bag,
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
     *
     * Багаж считается только НЕВОЗВРАЩЁННЫЙ: в legacy возврата одного
     * багажа не было вовсе, а у нас он есть — и без этой поправки деньги
     * за уже возвращённую квитанцию ушли бы покупателю второй раз.
     */
    private function refundAmount(Order $order, Ticket $ticket, int $percent): float
    {
        $gross = (float) $ticket->price + $this->baggageToRefund($order, $ticket) * (float) $ticket->price_bag;

        return round(
            (float) number_format($gross, 2, '.', '') * (float) number_format(1 - $percent / 100, 2, '.', ''),
            2,
        );
    }

    /** Сколько багажных мест билета ещё не возвращено отдельными квитанциями. */
    private function baggageToRefund(Order $order, Ticket $ticket): int
    {
        $refunded = Refund::where('order_id', $order->id)
            ->where('kind', Refund::KIND_BAGGAGE)
            ->whereIn('number', array_column($ticket->baggageBlanks(), 'number'))
            ->count();

        return max(0, (int) $ticket->baggage - $refunded);
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

    /**
     * Возврат САМОГО билета. Фильтр по kind обязателен: возврат удержания
     * хранится с номерами того же билета, и без фильтра карточка после
     * «вернуть удержание» показывала бы состояние по строке удержания.
     */
    private function findRefund(Order $order, Ticket $ticket): ?Refund
    {
        return Refund::where('order_id', $order->id)
            ->where('kind', Refund::KIND_TICKET)
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

    /**
     * Багажные квитанции билета и их состояние.
     *
     * Квитанции мы знаем из своей продажи (`tickets.baggage_tickets`), а не
     * из базы станции: там связь квитанции с билетом (MAIN_N_TICKET) не
     * заполняется, и искать их пришлось бы по пассажиру и рейсу.
     *
     * @return array<string, mixed>
     */
    private function baggageInfo(Order $order, Ticket $ticket): array
    {
        $returned = Refund::where('order_id', $order->id)
            ->where('kind', Refund::KIND_BAGGAGE)
            ->get()
            ->keyBy(fn (Refund $r) => $r->serial.'/'.$r->number);

        // Возврат билета включает багаж: и деньги (ticket + bag − процент),
        // и аннулирование квитанций на станции. После него вернуть багаж
        // ещё раз нельзя — это был бы второй возврат денег за то же место.
        $ticketRefund = $this->findRefund($order, $ticket);

        $items = [];

        foreach ($ticket->baggageBlanks() as $bag) {
            $key = $bag['serial'].'/'.$bag['number'];
            $refund = $returned->get($key) ?? $ticketRefund;

            $items[] = [
                'serial' => $bag['serial'],
                'number' => $bag['number'],
                'price' => (float) $ticket->price_bag,
                'refunded' => $refund !== null,
                'refunded_at' => $refund?->created_at?->format('d.m.Y H:i'),
                'refunded_amount' => $refund ? (float) $refund->amount : null,
                'with_ticket' => ! $returned->has($key) && $ticketRefund !== null,
            ];
        }

        $available = array_values(array_filter($items, fn (array $i) => ! $i['refunded']));

        return [
            'items' => $items,
            'price' => (float) $ticket->price_bag,
            // Багаж ушёл в возврат вместе с билетом — карточка говорит об
            // этом вместо кнопки «вернуть багаж».
            'refunded_with_ticket' => $ticketRefund !== null,
            // Сколько мест ещё можно вернуть и на какую сумму при каждом
            // проценте удержания — чтобы админ выбирал, а не считал.
            'available' => count($available),
            'variants' => $available === [] ? [] : array_map(fn (int $p) => [
                'percent' => $p,
                'amounts' => array_map(
                    fn (int $count) => $this->baggageAmount($ticket, $count, $p),
                    range(1, count($available)),
                ),
            ], self::PERCENTS),
        ];
    }

    /**
     * Вернуть ТОЛЬКО багаж: билет остаётся действующим, на станции
     * аннулируются отдельные багажные бланки (категория 9).
     */
    public function confirmBaggage(string $orderId, string $serial, string $number, int $count, int $percent): array
    {
        if (! in_array($percent, self::PERCENTS, true)) {
            throw ValidationException::withMessages(['percent' => 'Не выбран процент удержаний!']);
        }

        $order = $this->findOrder($orderId);
        $ticket = $this->findTicket($order, $serial, $number);

        if ($this->findRefund($order, $ticket)) {
            throw ValidationException::withMessages([
                'ticket' => 'Багаж возвращён вместе с билетом — отдельный возврат невозможен',
            ]);
        }

        $available = array_values(array_filter(
            $ticket->baggageBlanks(),
            fn (array $b) => ! Refund::where('order_id', $order->id)
                ->where('kind', Refund::KIND_BAGGAGE)
                ->where('serial', $b['serial'])
                ->where('number', $b['number'])
                ->exists(),
        ));

        if ($count < 1 || $count > count($available)) {
            throw ValidationException::withMessages([
                'count' => 'Столько мест багажа вернуть нельзя: доступно '.count($available),
            ]);
        }

        $selected = array_slice($available, 0, $count);

        // Аннулирование багажных бланков на станции — одним обращением.
        $result = $this->salesApi->refund([
            'awtcod' => $order->awtcod,
            'operation' => 1,
            'preview' => false,
            'admin_mode' => true,
            'tickets' => array_map(
                fn (array $b) => ['serial' => $b['serial'], 'number' => $b['number']],
                $selected,
            ),
        ])['data'];

        if ((float) ($result['total_refund'] ?? 0) <= 0) {
            throw new SalesApiException('Не удалось вернуть багаж, ошибка API автовокзала', 'refund_failed');
        }

        // Строка возврата на КАЖДУЮ квитанцию: так в истории видно, какие
        // именно бланки вернули, и повторный возврат той же невозможен.
        $amountPerBag = $this->baggageAmount($ticket, 1, $percent);
        $bankErrors = [];

        foreach ($selected as $bag) {
            $refund = Refund::create([
                'order_id' => $order->id,
                'kind' => Refund::KIND_BAGGAGE,
                'baggage_count' => 1,
                'serial' => $bag['serial'],
                'number' => $bag['number'],
                'place' => (int) $ticket->place,
                'penalty_percent' => $percent,
                'ticket_price' => 0,
                'baggage_price' => (float) $ticket->price_bag,
                'amount' => $amountPerBag,
                'status' => Refund::STATUS_REFUNDED,
                'legacy' => ['ticket_serial' => $ticket->serial, 'ticket_number' => $ticket->number],
            ]);

            if ($error = $this->refundMoney($refund, $order)) {
                $bankErrors[] = $error;
            }
        }

        $order->touch();

        return [
            'count' => $count,
            'amount' => round($amountPerBag * $count, 2),
            'trip_name' => $result['trip_name'] ?: $order->trip_name,
            'datetime_start' => $result['datetime_start'] ?? null,
            'bank_error' => $bankErrors[0] ?? null,
        ];
    }

    /**
     * Вернуть покупателю УДЕРЖАНИЕ по уже сделанному возврату.
     *
     * На станции такого возврата нет: ведомость удержание уже посчитала и
     * закрыла. Здесь только деньги — например, когда возврат задержался по
     * нашей вине и обещали вернуть удержание.
     */
    public function confirmPenalty(string $orderId, string $refundId): array
    {
        $order = $this->findOrder($orderId);

        $parent = Refund::where('order_id', $order->id)->find($refundId);

        if (! $parent) {
            throw ValidationException::withMessages(['refund' => 'Возврат не найден в этом заказе']);
        }

        if ($parent->kind === Refund::KIND_PENALTY) {
            throw ValidationException::withMessages(['refund' => 'Это сам возврат удержания']);
        }

        $withheld = $parent->withheld();

        if ($withheld <= 0) {
            throw ValidationException::withMessages(['refund' => 'По этому возврату удержания не было']);
        }

        if (Refund::where('parent_refund_id', $parent->id)->exists()) {
            throw ValidationException::withMessages(['refund' => 'Удержание по этому возврату уже возвращено']);
        }

        $refund = Refund::create([
            'order_id' => $order->id,
            'kind' => Refund::KIND_PENALTY,
            'parent_refund_id' => $parent->id,
            'serial' => $parent->serial,
            'number' => $parent->number,
            'place' => $parent->place,
            'penalty_percent' => 0,
            'ticket_price' => 0,
            'baggage_price' => 0,
            'amount' => $withheld,
            'status' => Refund::STATUS_REFUNDED,
            'legacy' => ['parent_percent' => $parent->penalty_percent],
        ]);

        $bankError = $this->refundMoney($refund, $order);
        $order->touch();

        return [
            'amount' => $withheld,
            'parent_percent' => (int) $parent->penalty_percent,
            'bank_error' => $bankError,
        ];
    }

    /**
     * Возвраты заказа — для списка в карточке и кнопки «вернуть удержание».
     *
     * @return array<int, array<string, mixed>>
     */
    private function orderRefunds(Order $order): array
    {
        $refunds = Refund::where('order_id', $order->id)->orderBy('created_at')->get();
        $penaltyReturned = $refunds->whereNotNull('parent_refund_id')->keyBy('parent_refund_id');

        return $refunds->map(function (Refund $r) use ($penaltyReturned) {
            $withheld = $r->withheld();

            return [
                'id' => $r->id,
                'kind' => $r->kind,
                'kind_label' => $r->kindLabel(),
                'serial' => $r->serial,
                'number' => $r->number,
                'place' => $r->place,
                'percent' => (int) $r->penalty_percent,
                'amount' => (float) $r->amount,
                'withheld' => $withheld,
                'status' => $r->status,
                'money_refunded' => $r->money_refunded_at !== null,
                'fiscal_sign' => $r->fiscal_sign,
                'created_at' => $r->created_at?->format('d.m.Y H:i'),
                // Удержание можно вернуть один раз и только там, где оно было.
                'penalty_returnable' => $r->kind !== Refund::KIND_PENALTY
                    && $withheld > 0
                    && ! $penaltyReturned->has($r->id),
                'penalty_returned' => $penaltyReturned->has($r->id),
            ];
        })->all();
    }

    /** Сумма возврата за N мест багажа с удержанием — формула как у билета. */
    private function baggageAmount(Ticket $ticket, int $count, int $percent): float
    {
        $gross = $count * (float) $ticket->price_bag;

        return round(
            (float) number_format($gross, 2, '.', '') * (float) number_format(1 - $percent / 100, 2, '.', ''),
            2,
        );
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
            'tickets' => $this->blanksToAnnul($order, $ticket),
        ])['data'];
    }

    /**
     * Бланки, которые аннулируются вместе с билетом: сам билет и ЕГО
     * БАГАЖНЫЕ КВИТАНЦИИ.
     *
     * Так делал legacy: `end_buy` складывал в каждый билет заказа ключ
     * `BAGAJ` со списком багажных бланков (`ORDERS.BILETS`), а
     * `tickets_back` в `ais/api/api2.php` дописывал их номера в
     * `I_N_TICKET` для KS_ANN_TICKET_FROM_AW. Процедура аннулирует ровно
     * то, что ей передали, сама багаж не трогает — поэтому без этого места
     * возвращалось только место, деньги за багаж покупателю уходили, а в
     * ведомости станции багаж оставался проданным (ловилось на проде
     * 16.11.2026 на билете 112657019984/159900159).
     *
     * Квитанции, возвращённые ОТДЕЛЬНО (возврат только багажа), пропускаем:
     * на станции они уже аннулированы, и повторное аннулирование отклонит
     * сама процедура.
     *
     * @return array<int, array{serial: string, number: string}>
     */
    private function blanksToAnnul(Order $order, Ticket $ticket): array
    {
        $blanks = [[
            'serial' => (string) $ticket->serial,
            'number' => (string) $ticket->number,
        ]];

        $refundedBaggage = Refund::where('order_id', $order->id)
            ->where('kind', Refund::KIND_BAGGAGE)
            ->get()
            ->map(fn (Refund $r) => $r->serial.'/'.$r->number)
            ->all();

        foreach ($ticket->baggageBlanks() as $bag) {
            if (in_array($bag['serial'].'/'.$bag['number'], $refundedBaggage, true)) {
                continue;
            }

            $blanks[] = $bag;
        }

        return $blanks;
    }
}
