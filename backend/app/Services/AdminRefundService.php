<?php

namespace App\Services;

use App\Jobs\RegisterFiscalReceipt;
use App\Jobs\RegisterRefundReceipt;
use App\Jobs\SendRefundEmail;
use App\Models\Order;
use App\Models\Refund;
use App\Models\Ticket;
use App\Support\StationRefundResult;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
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

    /**
     * Состояние бланков заказа в журнале станции — один запрос на заказ.
     *
     * Карточка спрашивает его в нескольких местах (список билетов, разбор
     * отказа станции, подбор бланков к аннулированию), а ходить на станцию
     * за одним и тем же по три раза незачем. Ключ — id заказа: сервис
     * живёт в контейнере и в одном процессе может обслужить разные заказы.
     *
     * @var array<string, array<string, array<string, mixed>>>
     */
    private array $stationBlanksCache = [];

    public function __construct(
        private readonly SalesApiClient $salesApi,
        private readonly PaymentGateway $payments,
        // Настройка «переносить дату возврата в журнале станции» (АИС).
        private readonly SettingService $settings,
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
            /*
             * Все билеты заказа с их состоянием. Без этого списка билет,
             * по которому вернули ТОЛЬКО деньги (станция была недоступна),
             * ничем не отличался от остальных — кассир не видел, что место
             * на станции до сих пор числится проданным.
             */
            'tickets' => $this->ticketsState($order),
            // Все возвраты заказа: по ним видно, что уже вернули и где
            // осталось неотданное покупателю удержание.
            'refunds' => $this->orderRefunds($order),
        ];

        /*
         * Заказ без билетов, но с деньгами: дооформление не состоялось
         * (например, станция была недоступна), а платёж списан. Такому
         * заказу нужен не возврат билета, а отмена платежа — и кассир
         * должен иметь возможность сделать это руками, не дожидаясь
         * автоматики (она ходит раз в 15 минут и только через час после
         * создания заказа).
         */
        $unfinished = $this->unfinishedPayment($order);

        // Как legacy: без номера билета показываем только информацию по заказу.
        if (! $serial || ! $number) {
            return $result + [
                'state' => $unfinished ? 'unfinished_payment' : 'no_ticket',
                'unfinished_payment' => $unfinished,
                'message' => $unfinished
                    ? $unfinished['message']
                    : 'Не передан номер билета к возврату',
            ];
        }

        $ticket = $this->findTicket($order, $serial, $number);
        $result['ticket'] = $this->ticketInfo($ticket);
        // Открыли по номеру багажной квитанции — карточка принадлежит её
        // билету, и об этом надо сказать прямо.
        $result['opened_by_baggage'] = (string) $ticket->number !== $number
            ? ['serial' => $serial, 'number' => $number]
            : null;
        // Багажные квитанции билета: их можно вернуть отдельно, не трогая
        // сам билет (на станции это самостоятельные бланки).
        $result['baggage'] = $this->baggageInfo($order, $ticket);

        $refund = $this->findRefund($order, $ticket);

        if ($refund) {
            return $result + $this->refundState($refund);
        }

        /*
         * Предпросмотр возврата на станции (ONLY_SHOW, admin_mode). Если
         * база станции не отвечает (у Кром, например, нет интернета),
         * карточка не должна превращаться в ошибку: деньги покупателю
         * вернуть всё равно нужно, поэтому предлагаем возврат без станции
         * и честно пишем, чем он отличается.
         */
        try {
            $preview = $this->stationRefund($order, $ticket, preview: true);
        } catch (SalesApiException $e) {
            /*
             * Станция ОТВЕТИЛА отказом: «билет не найден в БД вокзала»,
             * «ведомость закрыта», «нет разрешения на возврат после
             * отправления». Карточку из-за этого рушить нельзя — кассиру
             * нужны и заказ, и список билетов, и прошлые возвраты, чтобы
             * понять, что делать дальше (раньше он видел только красную
             * строку и закрывал окно).
             */
            if ($e->errorCode !== 'station_unavailable') {
                return $result + $this->stationRejectedState($order, $ticket, $e);
            }

            return $result + [
                'state' => 'refundable_without_station',
                'station_unavailable' => true,
                'station_error' => $e->getMessage(),
                'trip_name' => $order->trip_name,
                'datetime_start' => null,
                // Станция процент не подскажет — выбирает кассир.
                'recommended_percent' => -1,
                'variants' => array_map(fn (int $p) => [
                    'percent' => $p,
                    'amount' => $this->refundAmount($order, $ticket, $p),
                ], self::PERCENTS),
                'message' => 'База станции недоступна. Можно вернуть покупателю деньги сейчас, '
                    .'а аннулирование билета на станции дослать позже — место до этого '
                    .'останется занятым, и сумму станция не подскажет: процент удержания '
                    .'выбирается вручную.',
            ];
        }

        $recommended = (int) round((float) ($preview['penalties'][0] ?? 0));

        return $result + [
            'state' => 'refundable',
            'station_unavailable' => false,
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
    public function confirm(
        string $orderId,
        string $serial,
        string $number,
        int $percent,
        bool $withoutStation = false,
        bool $withReceipt = true,
        bool $alreadyAnnulled = false,
    ): array {
        // Legacy: PROC<0 → «Не выбран процент удержаний!»; варианты — из формы.
        if (! in_array($percent, self::PERCENTS, true)) {
            throw ValidationException::withMessages(['percent' => 'Не выбран процент удержаний!']);
        }

        /*
         * Блокировка на бланк: двойной клик по «Выполнить возврат» иначе
         * дважды уходит на станцию (второй раз она откажет) и дважды — в
         * шлюз. Операции с деньгами и ведомостью станции задваиваться не
         * должны ни при каких обстоятельствах.
         */
        return $this->withBlankLock($orderId, $serial, $number, fn () => $this->confirmLocked(
            $orderId, $serial, $number, $percent, $withoutStation, $withReceipt, $alreadyAnnulled,
        ));
    }

    /** @return array<string, mixed> */
    private function confirmLocked(
        string $orderId,
        string $serial,
        string $number,
        int $percent,
        bool $withoutStation,
        bool $withReceipt,
        bool $alreadyAnnulled = false,
    ): array {

        $order = $this->findOrder($orderId);
        $ticket = $this->findTicket($order, $serial, $number);

        if ($this->findRefund($order, $ticket)) {
            throw ValidationException::withMessages(['ticket' => 'Билет уже возвращён']);
        }

        /*
         * Станция недоступна (у Кром, например, нет интернета), а покупатель
         * просит деньги. Тогда возвращаем ДЕНЬГИ сразу, а аннулирование
         * бланка в ведомости станции досылаем позже кнопкой в АИС.
         *
         * Разрешаем это только когда станция ДЕЙСТВИТЕЛЬНО не отвечает:
         * обычный путь надёжнее (сумму и процент подсказывает станция, место
         * сразу освобождается), и подменять его «быстрым» нельзя.
         */
        if ($withoutStation) {
            $this->assertStationUnavailable($order, $ticket);

            return $this->confirmWithoutStation($order, $ticket, $percent, $withReceipt);
        }

        /*
         * Бланк на станции УЖЕ аннулирован, а денег покупатель не получил.
         * Аннулировать нечего — остаётся вернуть деньги и завести запись
         * возврата, чтобы журнал и отчёты сошлись.
         */
        if ($alreadyAnnulled) {
            return $this->confirmAlreadyAnnulled($order, $ticket, $percent, $withReceipt);
        }

        // Аннулирование в ведомости станции (back=true, admin_mode).
        $result = $this->stationRefund($order, $ticket, preview: false);

        /*
         * Успех — это «станция приняла ВСЕ бланки», а не «сумма возврата
         * больше нуля». Прежняя проверка `total_refund <= 0` была опасной:
         * станция честно отдаёт ноль там, где по её ведомости возвращать
         * нечего (бесплатная багажная квитанция, полное удержание), — и мы
         * бросали «не удалось вернуть» ПОСЛЕ того, как бланк на станции
         * уже аннулирован, не создав записи возврата. Так 17.11.2026
         * потерялся возврат по билету 112657019984/099900079.
         */
        StationRefundResult::assertAllBlanksAnnulled($result);

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
            // Бланк аннулирован сейчас же — обычный путь.
            'station_annulled_at' => now(),
        ]);
        $order->touch();

        $bankError = $this->refundMoney($refund, $order, $withReceipt);

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
     * Заказ, по которому деньги списаны, а билетов нет.
     *
     * Возвращает null, если возвращать нечего: билеты оформлены, платежа
     * не было или деньги уже вернули. Состояние платежа спрашиваем у
     * шлюза — наш статус мог испортиться (так и было с заказом, где
     * станция оказалась недоступна при дооформлении).
     *
     * @return array<string, mixed>|null
     */
    private function unfinishedPayment(Order $order): ?array
    {
        if ($order->tickets()->exists() || ! $order->sber_order_id) {
            return null;
        }

        $bank = $this->bankInfo($order);
        $status = $bank['order_status'] ?? null;

        // 2 = «успешно оплачен» в терминах шлюза: деньги у нас.
        $paid = $status === 2;

        return [
            'paid' => $paid,
            'amount' => (float) $order->total_price,
            'gateway_status' => $status,
            'gateway_status_text' => $bank['status_text'] ?? null,
            'refunded_amount' => $bank['refunded'] ?? null,
            'can_cancel' => $paid,
            'message' => $paid
                ? 'Билеты по этому заказу не оформлены, а деньги списаны ('
                    .number_format((float) $order->total_price, 2, ',', ' ').' руб.). '
                    .'Возвращать билет нечего — деньги надо вернуть покупателю: сначала '
                    .'пробуем отмену платежа, а если он уже списан, шлюз проведёт полный '
                    .'возврат. Бронь мест снимется, заказ закроется.'
                : 'Билетов по заказу нет, и денег у нас тоже нет (шлюз: '
                    .($bank['status_text'] ?? $bank['error'] ?? 'статус неизвестен').'). Возвращать нечего.',
        ];
    }

    /**
     * Отменить платёж по заказу без билетов — вручную, кнопкой кассира.
     *
     * Делает ровно то же, что автоматика брошенных покупок
     * (`AbandonedPaymentService`): сначала отмену, при отказе — полный
     * возврат, затем снимает бронь и закрывает заказ. Разойтись этим двум
     * путям нельзя, поэтому логика одна.
     *
     * @return array<string, mixed>
     */
    public function cancelPayment(string $orderId, ?string $by = null): array
    {
        $order = $this->findOrder($orderId);

        if ($order->tickets()->exists()) {
            throw ValidationException::withMessages([
                'order' => 'По заказу есть оформленные билеты — отменять платёж нельзя, '
                    .'возвращайте билеты по одному.',
            ]);
        }

        if (! $order->sber_order_id) {
            throw ValidationException::withMessages([
                'order' => 'У заказа нет платежа в шлюзе — отменять нечего.',
            ]);
        }

        $result = app(AbandonedPaymentService::class)->resolve($order, $by);

        Log::info('Платёж по неоформленному заказу отменён вручную', [
            'order_id' => $order->id,
            'by' => $by,
            'action' => $result['action'],
        ]);

        return $result + [
            'order' => $this->orderInfo($order->refresh()),
        ];
    }

    /**
     * Пробить фискальный чек по операции возврата вручную.
     *
     * Зачем кнопка: чек ставится в очередь `receipts`, а её воркер
     * запускается не всегда — и у возврата остаётся «нет чека». Плюс чек
     * мог не пробиться из-за ККТ (закрытая смена, нет связи), и повторять
     * попытку должен человек, видя результат сразу. Поэтому выполняем
     * СИНХРОННО, а не кладём в очередь.
     *
     * Идемпотентность — на стороне KKM API: ключ `refund:{id}` не пробьёт
     * второй чек по той же операции (см. CLAUDE.md про external_id).
     *
     * @return array<string, mixed>
     */
    public function registerReceipt(string $orderId, string $refundId): array
    {
        $order = $this->findOrder($orderId);
        $refund = Refund::where('order_id', $order->id)->find($refundId);

        if (! $refund) {
            throw ValidationException::withMessages(['refund' => 'Возврат не найден в этом заказе']);
        }

        if ($refund->fiscal_sign) {
            return [
                'fiscal_sign' => $refund->fiscal_sign,
                'message' => 'Чек по этой операции уже пробит, ФП '.$refund->fiscal_sign.'.',
            ];
        }

        if ((float) $refund->amount <= 0) {
            throw ValidationException::withMessages([
                'refund' => 'Это отметка об аннулировании бланка — чек по ней не нужен: '
                    .'деньги по ней не возвращались.',
            ]);
        }

        if (! $refund->money_refunded_at) {
            throw ValidationException::withMessages([
                'refund' => 'Деньги по этой операции ещё не вернулись — чек возврата пробивать рано. '
                    .'Сначала выполните возврат по банку.',
            ]);
        }

        // Замок: двойной клик не должен отправлять в ККТ две команды.
        $lock = Cache::lock('refund-receipt:'.$refund->id, 120);

        if (! $lock->get()) {
            throw ValidationException::withMessages([
                'refund' => 'Чек по этой операции уже пробивается — дождитесь результата.',
            ]);
        }

        try {
            // Синхронно: сотрудник должен увидеть результат, а не «поставлено в очередь».
            RegisterRefundReceipt::dispatchSync($refund->id);
        } catch (\Throwable $e) {
            Log::warning('Ручная фискализация возврата не удалась', [
                'order_id' => $order->id,
                'refund_id' => $refund->id,
                'error' => $e->getMessage(),
            ]);

            throw ValidationException::withMessages([
                'refund' => 'Чек пробить не удалось: '.$e->getMessage(),
            ]);
        } finally {
            $lock->release();
        }

        $refund->refresh();

        return [
            'fiscal_sign' => $refund->fiscal_sign,
            'message' => $refund->fiscal_sign
                ? 'Чек пробит, ФП '.$refund->fiscal_sign.'.'
                : 'Команда отправлена в ККТ, но фискальный признак пока не получен. '
                    .'Проверьте состояние ККТ и повторите через минуту.',
        ];
    }

    /**
     * Пробить фискальный чек ПОКУПКИ вручную.
     *
     * Чек продажи ставится в очередь при оформлении и мог не пробиться
     * (ККТ без связи, закрытая смена, воркер не запущен) — тогда у заказа
     * нет фискального признака, а 54-ФЗ его требует. Выполняем синхронно,
     * чтобы кассир сразу видел результат; второй чек по тому же ключу ККТ
     * не пробьёт.
     *
     * @return array<string, mixed>
     */
    public function registerSaleReceipt(string $orderId): array
    {
        $order = $this->findOrder($orderId);

        if ($order->fiscal_sign) {
            return [
                'fiscal_sign' => $order->fiscal_sign,
                'message' => 'Чек покупки уже пробит, ФП '.$order->fiscal_sign.'.',
            ];
        }

        if ($order->status !== Order::STATUS_COMPLETED) {
            throw ValidationException::withMessages([
                'order' => 'Заказ не оформлен — чек покупки пробивать нечему. '
                    .'Сначала разберитесь с оформлением или отмените платёж.',
            ]);
        }

        $lock = Cache::lock('order-receipt:'.$order->id, 120);

        if (! $lock->get()) {
            throw ValidationException::withMessages([
                'order' => 'Чек покупки уже пробивается — дождитесь результата.',
            ]);
        }

        try {
            RegisterFiscalReceipt::dispatchSync($order->id);
        } catch (\Throwable $e) {
            Log::warning('Ручная фискализация покупки не удалась', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);

            throw ValidationException::withMessages([
                'order' => 'Чек покупки пробить не удалось: '.$e->getMessage(),
            ]);
        } finally {
            $lock->release();
        }

        $order->refresh();

        return [
            'fiscal_sign' => $order->fiscal_sign,
            'message' => $order->fiscal_sign
                ? 'Чек покупки пробит, ФП '.$order->fiscal_sign.'.'
                : 'Команда отправлена в ККТ, но фискальный признак пока не получен. '
                    .'Проверьте состояние ККТ и повторите через минуту.',
        ];
    }

    /**
     * Выполнить операцию под замком на бланк: одна операция возврата по
     * одному билету за раз.
     *
     * Иначе двойной клик (или «ничего не произошло, нажму ещё раз» после
     * долгого ответа станции) превращается в две операции с деньгами
     * покупателя и двумя обращениями к ведомости станции.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    private function withBlankLock(string $orderId, string $serial, string $number, callable $callback)
    {
        $lock = Cache::lock("refund-blank:{$orderId}:{$serial}:{$number}", 120);

        if (! $lock->get()) {
            throw ValidationException::withMessages([
                'ticket' => 'Операция по этому билету уже выполняется — дождитесь результата, '
                    .'не нажимайте повторно.',
            ]);
        }

        try {
            return $callback();
        } finally {
            $lock->release();
        }
    }

    /**
     * Убедиться, что база станции действительно не отвечает.
     *
     * Спрашиваем станцию предварительным расчётом (preview — он ничего не
     * меняет): ответила — значит возврат надо делать обычным путём, и
     * «возврат без станции» мы не разрешаем. Отказала недоступностью —
     * путь открыт.
     */
    private function assertStationUnavailable(Order $order, Ticket $ticket): void
    {
        try {
            $this->stationRefund($order, $ticket, preview: true);
        } catch (SalesApiException $e) {
            if ($e->errorCode === 'station_unavailable') {
                return;
            }

            /*
             * Станция ответила, но возврат отклонила (билет уже возвращён,
             * бланк не найден). Это НЕ повод обходить станцию — причину
             * показываем как есть.
             */
            throw $e;
        }

        throw ValidationException::withMessages([
            'ticket' => 'Станция отвечает — выполните обычный возврат, он надёжнее: '
                .'станция подскажет сумму и сразу освободит место.',
        ]);
    }

    /**
     * Возврат ДЕНЕГ без аннулирования на станции.
     *
     * Сумму считаем по своим данным (цена билета, багаж, процент админа) —
     * станцию спросить не можем. Место на станции до досылки остаётся
     * проданным: его не продадут второй раз, но и свободным оно не станет.
     * Это осознанная плата за то, чтобы покупатель получил деньги сегодня,
     * а не «когда появится интернет».
     *
     * @return array<string, mixed>
     */
    private function confirmWithoutStation(
        Order $order,
        Ticket $ticket,
        int $percent,
        bool $withReceipt = true,
    ): array {
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
            // Главное отличие: на станции бланк ещё НЕ аннулирован.
            'station_annulled_at' => null,
            'station_annul_pending' => true,
            'station_annul_error' => 'База станции недоступна на момент возврата денег',
            'station_annul_attempted_at' => now(),
        ]);
        $order->touch();

        $bankError = $this->refundMoney($refund, $order, $withReceipt);

        SendRefundEmail::dispatch($refund->id)->onQueue('emails');

        Log::warning('Возврат денег без аннулирования на станции', [
            'order_id' => $order->id,
            'refund_id' => $refund->id,
            'awtcod' => $order->awtcod,
            'ticket' => $ticket->serial.'/'.$ticket->number,
            'amount' => $amount,
        ]);

        return [
            'trip_name' => $order->trip_name,
            'datetime_start' => null,
            'amount' => $amount,
            'bank_error' => $bankError,
            'station_annul_pending' => true,
            'message' => 'Деньги возвращены. На станции билет ПОКА НЕ аннулирован — '
                .'дошлите аннулирование, когда у станции появится связь '
                .'(раздел «Возвраты без станции»). До этого место остаётся занятым.',
        ];
    }

    /**
     * Дослать аннулирование на станцию по возврату, сделанному без неё.
     *
     * Дата возврата в журнале станции переносится на дату, когда деньги
     * реально вернули, — если это включено настройкой
     * `refund_station_backdate` (там же сказано, что сводные финансовые
     * итоги станции при этом остаются на дате досылки).
     *
     * @return array<string, mixed>
     */
    public function annulAtStation(string $orderId, string $refundId): array
    {
        $order = $this->findOrder($orderId);
        $refund = Refund::where('order_id', $order->id)->find($refundId);

        if (! $refund) {
            throw ValidationException::withMessages(['refund' => 'Возврат не найден в этом заказе']);
        }

        if (! $refund->station_annul_pending || $refund->station_annulled_at) {
            throw ValidationException::withMessages([
                'refund' => 'Этот возврат на станции уже аннулирован — досылать нечего.',
            ]);
        }

        if ($refund->kind === Refund::KIND_PENALTY) {
            throw ValidationException::withMessages([
                'refund' => 'Возврат удержания станции не касается: аннулировать нечего.',
            ]);
        }

        $ticket = $this->findTicket($order, (string) $refund->serial, (string) $refund->number);
        $backdate = $this->settings->get('refund_station_backdate')
            ? ($refund->money_refunded_at ?? $refund->created_at)->format('Y-m-d H:i:s')
            : null;

        try {
            $result = $this->salesApi->refund(array_filter([
                'awtcod' => $order->awtcod,
                'operation' => 1,
                'preview' => false,
                'admin_mode' => true,
                'tickets' => $this->blanksToAnnul($order, $ticket),
                // Ключ не передаём вовсе, когда перенос выключен: пустое
                // значение API продаж отвергает проверкой «date».
                'backdate' => $backdate,
            ], fn ($v) => $v !== null))['data'];

            // Станция приняла ВСЕ бланки? Иначе возврат пометился бы
            // аннулированным, а бланк на станции остался проданным.
            StationRefundResult::assertAllBlanksAnnulled($result);
        } catch (SalesApiException $e) {
            // Станция всё ещё недоступна — это не ошибка оператора, просто
            // ждём дальше. Остальные отказы показываем как есть.
            $refund->update([
                'station_annul_attempted_at' => now(),
                'station_annul_error' => mb_substr($e->getMessage(), 0, 500),
            ]);

            throw $e;
        }

        $backdateResult = $result['backdate'] ?? null;

        $refund->update([
            'station_annulled_at' => now(),
            'station_annul_pending' => false,
            'station_annul_attempted_at' => now(),
            'station_annul_error' => null,
            'station_backdated_at' => ($backdateResult['updated_rows'] ?? 0) > 0 ? now() : null,
        ]);
        $order->touch();

        return [
            'annulled_at' => $refund->station_annulled_at?->format('d.m.Y H:i'),
            'total_refund' => (float) ($result['total_refund'] ?? 0),
            'backdate' => $backdateResult,
            'message' => $this->annulMessage($refund, $backdate, $backdateResult),
        ];
    }

    /** @param  array<string, mixed>|null  $backdateResult */
    private function annulMessage(Refund $refund, ?string $backdate, ?array $backdateResult): string
    {
        $base = 'Билет аннулирован на станции, место освободилось.';

        if ($backdate === null) {
            return $base.' Дата возврата в журнале станции — сегодняшняя '
                .'(перенос даты выключен в настройках сайта).';
        }

        $rows = (int) ($backdateResult['updated_rows'] ?? 0);
        $errors = $backdateResult['errors'] ?? [];

        if ($rows > 0) {
            return $base.' Дата возврата в журнале станции перенесена на '
                .($refund->money_refunded_at ?? $refund->created_at)->format('d.m.Y H:i')
                .'. Сводные финансовые итоги станции (КСР) остались на сегодняшней дате.';
        }

        return $base.' Перенести дату возврата не удалось'
            .($errors ? ': '.implode('; ', (array) $errors) : '')
            .' — в журнале станции осталась сегодняшняя дата.';
    }

    /**
     * Починка зависшего возврата: билет аннулирован, а деньги в банк не ушли
     * (legacy-ветка «Не прошёл возврат денег по банку»).
     */
    public function repairMoney(
        string $orderId,
        ?string $serial,
        ?string $number,
        ?string $refundId = null,
        bool $withReceipt = true,
    ): array {
        $order = $this->findOrder($orderId);

        /*
         * Чинить приходится не только возврат билета: зависнуть может и
         * возврат удержания, и возврат багажа (у них свои обращения к
         * шлюзу). Поэтому строку можно указать прямо — кнопка в списке
         * возвратов заказа так и делает.
         */
        if ($refundId !== null) {
            $refund = Refund::where('order_id', $order->id)->find($refundId);

            if (! $refund) {
                throw ValidationException::withMessages(['refund' => 'Возврат не найден в этом заказе']);
            }
        } else {
            if ($serial === null || $number === null) {
                throw ValidationException::withMessages(['ticket' => 'Не передан номер билета к возврату']);
            }

            $ticket = $this->findTicket($order, $serial, $number);
            $refund = $this->findRefund($order, $ticket);
        }

        if (! $refund) {
            throw ValidationException::withMessages(['ticket' => 'Возврат этого билета не найден']);
        }
        // Legacy-условие: DATE_MONEY_BACK пуст и SUMM_BACK есть.
        if ($refund->money_refunded_at || (float) $refund->amount <= 0) {
            throw ValidationException::withMessages(['ticket' => 'Возврат денег по этому билету уже прошёл']);
        }

        $bankError = $this->refundMoney($refund, $order, $withReceipt);

        return [
            'amount' => (float) $refund->amount,
            'bank_error' => $bankError,
            'refund_id' => $refund->id,
            'bank_response' => $refund->refresh()->bank_response,
        ];
    }

    /**
     * Возврат платежа в шлюзе + чек. При отказе банка возврат остаётся
     * bank_failed — деньги вернёт бухгалтерия (текст legacy).
     */
    private function refundMoney(Refund $refund, Order $order, bool $withReceipt = true): ?string
    {
        /*
         * ЛОВУШКА КОПЕЙКИ: floor здесь нельзя. 8.95 * 100 в двоичной
         * арифметике даёт 894.9999…, и floor превращал это в 894 — мы
         * возвращали покупателю на копейку меньше, чем написано в карточке
         * и в чеке (ловилось при проверке 16.11.2026). Сумма возврата
         * считается своей формулой (round, как в legacy back_bilet), а
         * здесь только перевод рублей в копейки — и он обязан быть точным.
         */
        $kopecks = (int) round((float) $refund->amount * 100);

        try {
            $this->payments->refund($order->sber_order_id, $kopecks);
            $refund->update([
                'status' => Refund::STATUS_MONEY_REFUNDED,
                'money_refunded_at' => now(),
                'bank_error' => null,
                'bank_response' => null,
            ]);

            /*
             * Чек возврата обычно обязателен (54-ФЗ), но бывает нужно
             * вернуть деньги БЕЗ чека — например когда чек уже пробит
             * вручную на кассе. Тогда кассир снимает галочку, а пробить
             * чек можно позже кнопкой в карточке.
             */
            if ($withReceipt) {
                RegisterRefundReceipt::dispatch($refund->id)->onQueue('receipts');
            }

            return null;
        } catch (\Throwable $e) {
            $refund->update([
                'status' => Refund::STATUS_BANK_FAILED,
                'bank_error' => $e->getMessage(),
                // Шлюз на отказ отвечает «Системная ошибка.» — по ней
                // причину не найти, поэтому складываем рядом всё, что
                // знаем о попытке. Это показывается сотруднику.
                'bank_response' => $this->bankFailureDetails($refund, $order, $kopecks, $e),
            ]);

            // Текст в `bank_error` короткий («Системная ошибка.»), а причина
            // отказа видна только в ответе шлюза — без лога разбираться
            // потом не по чему.
            Log::warning('Шлюз отказал в возврате платежа', [
                'order_id' => $order->id,
                'refund_id' => $refund->id,
                'refund_kind' => $refund->kind,
                'amount' => (float) $refund->amount,
                'sber_order_id' => $order->sber_order_id,
                'exception' => $e::class,
                'code' => $e->getCode(),
                'message' => $e->getMessage(),
            ]);

            return 'Не удалось вернуть платёж. Он будет возвращён в ближайшее время бухгалтерией';
        }
    }

    /**
     * Всё, что известно о неудачной попытке возврата: что отправили, что
     * ответил шлюз и в каком состоянии платёж СЕЙЧАС.
     *
     * Снимок состояния — главное: у отказа «Системная ошибка.» (код 7)
     * настоящей причиной обычно оказывается `paymentState = REFUNDED`, то
     * есть шлюз считает платёж закрытым возвратом и остаток возвращать не
     * даёт. По одному тексту ошибки этого не увидеть.
     *
     * @return array<string, mixed>
     */
    private function bankFailureDetails(Refund $refund, Order $order, int $kopecks, \Throwable $e): array
    {
        $details = [
            'at' => now()->format('d.m.Y H:i:s'),
            'request' => [
                'sber_order_id' => $order->sber_order_id,
                'amount' => (float) $refund->amount,
                'amount_kopecks' => $kopecks,
                'refund_kind' => $refund->kind,
                'refund_id' => $refund->id,
            ],
            'error' => [
                'class' => $e::class,
                'code' => $e->getCode(),
                'message' => $e->getMessage(),
            ],
        ];

        try {
            $status = $this->payments->statusExtended((string) $order->sber_order_id);
            $info = $status['paymentAmountInfo'] ?? [];

            $details['gateway'] = [
                'order_status' => $status['orderStatus'] ?? null,
                'order_status_text' => self::ORDER_STATUS_TEXT[(int) ($status['orderStatus'] ?? -1)] ?? null,
                'payment_state' => $info['paymentState'] ?? null,
                'amount' => isset($status['amount']) ? ((int) $status['amount']) / 100 : null,
                'refunded' => isset($info['refundedAmount']) ? ((int) $info['refundedAmount']) / 100 : null,
                'deposited' => isset($info['depositedAmount']) ? ((int) $info['depositedAmount']) / 100 : null,
                'action_code_description' => $status['actionCodeDescription'] ?? null,
            ];
        } catch (\Throwable $statusError) {
            $details['gateway'] = ['error' => $statusError->getMessage()];
        }

        return $details;
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

    /**
     * Сколько багажных мест билета ещё не возвращено отдельными квитанциями.
     *
     * Кроме наших записей учитываются квитанции, аннулированные на станции
     * ЧУЖИМ агентом (код агента не наш): их возврат оформила касса
     * станции, деньги покупателю выданы там — второй раз за тот же багаж
     * платить нельзя. Квитанция, аннулированная НАШИМ агентом без записи
     * у нас, из суммы не вычитается: денег по ней покупатель не получал
     * (ровно случай 17.11.2026, где запись потерялась после отказа).
     */
    private function baggageToRefund(Order $order, Ticket $ticket): int
    {
        $blanks = $ticket->baggageBlanks();

        $ourRefunds = Refund::where('order_id', $order->id)
            ->where('kind', Refund::KIND_BAGGAGE)
            ->whereIn('number', array_column($blanks, 'number'))
            ->pluck('number')
            ->map(fn ($n) => (string) $n)
            ->all();

        $settled = 0;

        foreach ($blanks as $bag) {
            if (in_array((string) $bag['number'], $ourRefunds, true)) {
                $settled++;

                continue;
            }

            $annulment = $this->stationAnnulment($order, (string) $bag['serial'], (string) $bag['number']);

            if ($annulment !== null && ! ($annulment['by_our_agent'] ?? false)) {
                $settled++;
            }
        }

        return max(0, (int) $ticket->baggage - $settled);
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

        if ($ticket) {
            return $ticket;
        }

        /*
         * Пришли по номеру БАГАЖНОЙ квитанции — так ведёт ссылка «Бланк» из
         * отчёта «Возвраты сайта», где у возврата багажа свои номера.
         * Показываем карточку билета, которому эта квитанция принадлежит,
         * вместо «Нет билетов с такими номерами в заказе».
         */
        $byBaggage = $order->tickets->first(
            fn (Ticket $t) => collect($t->baggageBlanks())->contains(
                fn (array $b) => $b['serial'] === $serial && $b['number'] === $number,
            ),
        );

        if ($byBaggage) {
            return $byBaggage;
        }

        throw ValidationException::withMessages(['ticket' => 'Нет билетов с такими номерами в заказе']);
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
        /*
         * Возврат сделан при недоступной станции: деньги могли уже уйти, но
         * бланк на станции не аннулирован. Это главное, что нужно показать
         * кассиру — остальные состояния (чек, банк) ниже.
         */
        if ($refund->station_annul_pending && ! $refund->station_annulled_at) {
            return [
                'state' => 'station_annul_pending',
                'message' => $refund->money_refunded_at
                    ? 'Деньги возвращены, но на станции билет НЕ аннулирован — место занято. '
                        .'Дошлите аннулирование, когда у станции появится связь.'
                    : 'На станции билет не аннулирован, и деньги по банку не прошли. '
                        .'Нужно и дослать аннулирование, и вернуть платёж.',
                'refund' => $this->refundInfo($refund),
            ];
        }

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
    public function confirmBaggage(
        string $orderId,
        string $serial,
        string $number,
        int $count,
        int $percent,
        bool $withReceipt = true,
    ): array {
        return $this->withBlankLock($orderId, $serial, $number, fn () => $this->confirmBaggageLocked(
            $orderId, $serial, $number, $count, $percent, $withReceipt,
        ));
    }

    /** @return array<string, mixed> */
    private function confirmBaggageLocked(
        string $orderId,
        string $serial,
        string $number,
        int $count,
        int $percent,
        bool $withReceipt,
    ): array {
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

        /*
         * Признак успеха — принятые станцией бланки, а НЕ сумма возврата.
         * Именно здесь всё и началось 17.11.2026: багаж у заказа
         * бесплатный (`price_bag = 0`), станция аннулировала квитанцию и
         * честно ответила нулём, а мы сочли это ошибкой и не записали
         * возврат. Дальше возврат самого билета натыкался на уже
         * аннулированную квитанцию, и билет «пропал» со станции без денег
         * покупателю.
         */
        StationRefundResult::assertAllBlanksAnnulled($result);

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

            if ($error = $this->refundMoney($refund, $order, $withReceipt)) {
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
    public function confirmPenalty(
        string $orderId,
        string $refundId,
        ?float $amount = null,
        bool $withReceipt = true,
    ): array {
        /*
         * Блокировка на родительский возврат: без неё двойной клик (или
         * повторное нажатие после отказа банка) создавал НОВУЮ запись
         * возврата удержания. На проде так появилось пять строк по 8,95 ₽,
         * все `bank_failed` — журнал возвратов распух, а денег покупатель
         * не получил (16.11.2026, заказ 01a1d671-…).
         */
        $lock = Cache::lock('refund-penalty:'.$refundId, 60);

        if (! $lock->get()) {
            throw ValidationException::withMessages([
                'refund' => 'Возврат удержания по этому билету уже выполняется — подождите результата.',
            ]);
        }

        try {
            return $this->confirmPenaltyLocked($orderId, $refundId, $amount, $withReceipt);
        } finally {
            $lock->release();
        }
    }

    /** @return array<string, mixed> */
    private function confirmPenaltyLocked(
        string $orderId,
        string $refundId,
        ?float $amount,
        bool $withReceipt,
    ): array {
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

        // Отметка об аннулировании бланка (сумма 0): деньги по ней не
        // проходили, возвращать нечего.
        if ((float) $parent->amount <= 0) {
            throw ValidationException::withMessages([
                'refund' => 'Это отметка об аннулировании бланка, деньги по ней не возвращались',
            ]);
        }

        /*
         * Удержание возвращается ЧАСТЯМИ: кассиру нередко надо отдать
         * покупателю лишь часть, а остальное — позже (или не отдавать
         * вовсе). Поэтому считаем остаток, а не запрещаем второй возврат.
         */
        $left = $this->penaltyLeft($parent);

        if ($left <= 0) {
            throw ValidationException::withMessages(['refund' => 'Удержание по этому возврату уже возвращено']);
        }

        $amount = $amount === null ? $left : round($amount, 2);

        if ($amount <= 0) {
            throw ValidationException::withMessages(['amount' => 'Сумма возврата удержания должна быть больше нуля']);
        }

        if ($amount > $left) {
            throw ValidationException::withMessages([
                'amount' => 'Больше остатка удержания вернуть нельзя: осталось '
                    .number_format($left, 2, '.', '').' руб.',
            ]);
        }

        /*
         * Повтор после отказа банка НЕ создаёт новую запись: берём ту же
         * и пробуем платёж снова. Иначе каждое нажатие множило строки
         * возвратов (деньги при этом не уходили), и отчёты показывали
         * возвраты, которых не было.
         */
        $refund = Refund::where('parent_refund_id', $parent->id)
            ->where('kind', Refund::KIND_PENALTY)
            ->whereNull('money_refunded_at')
            ->orderByDesc('created_at')
            ->first();

        $retry = $refund !== null;

        if ($retry) {
            $refund->update([
                'amount' => $amount,
                'status' => Refund::STATUS_REFUNDED,
                'legacy' => array_merge((array) $refund->legacy, [
                    'parent_percent' => $parent->penalty_percent,
                    'parent_withheld' => $withheld,
                    'partial' => $amount < $left,
                    // Сколько раз уже пробовали — видно в карточке.
                    'attempts' => (int) (($refund->legacy['attempts'] ?? 1)) + 1,
                ]),
            ]);
        } else {
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
                'amount' => $amount,
                'status' => Refund::STATUS_REFUNDED,
                'legacy' => [
                    'parent_percent' => $parent->penalty_percent,
                    'parent_withheld' => $withheld,
                    'partial' => $amount < $left,
                    'attempts' => 1,
                ],
            ]);
        }

        $bankError = $this->refundMoney($refund, $order, $withReceipt);
        $order->touch();

        return [
            'amount' => $amount,
            'withheld' => $withheld,
            // Сколько удержания осталось неотданным после этой операции.
            'left' => round($left - $amount, 2),
            'parent_percent' => (int) $parent->penalty_percent,
            'bank_error' => $bankError,
            'retry' => $retry,
            'refund_id' => $refund->id,
            // Техника отказа — сотруднику её надо видеть целиком.
            'bank_response' => $refund->refresh()->bank_response,
        ];
    }

    /**
     * Сколько удержания по возврату ещё не отдано покупателю.
     *
     * Считаем по УСПЕШНЫМ и ожидающим банк строкам удержания (деньги либо
     * ушли, либо уйдут), а отказ банка не уменьшает остаток — иначе
     * неудачная попытка «съедала» бы удержание.
     */
    private function penaltyLeft(Refund $parent): float
    {
        $returned = (float) Refund::where('parent_refund_id', $parent->id)
            ->where('status', '!=', Refund::STATUS_BANK_FAILED)
            ->sum('amount');

        return round($parent->withheld() - $returned, 2);
    }

    /**
     * Возвраты заказа — для списка в карточке и кнопки «вернуть удержание».
     *
     * @return array<int, array<string, mixed>>
     */
    private function orderRefunds(Order $order): array
    {
        $refunds = Refund::where('order_id', $order->id)->orderBy('created_at')->get();

        return $refunds->map(function (Refund $r) {
            $withheld = $r->withheld();
            $left = $withheld > 0 ? $this->penaltyLeft($r) : 0.0;

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
                // Удержание возвращается частями — пока остаток не исчерпан.
                'penalty_returnable' => $r->kind !== Refund::KIND_PENALTY
                    && $withheld > 0
                    && $left > 0,
                'penalty_left' => $left,
                // Деньги по строке не ушли, а сумма есть — можно повторить
                // обращение к шлюзу (кнопка «Вернуть по банку» в списке).
                'money_repairable' => $r->money_refunded_at === null && (float) $r->amount > 0,
                // Чек по операции можно пробить вручную: деньги вернулись,
                // а фискального признака нет (очередь чеков могла не
                // отработать или ККТ отказала).
                'receipt_needed' => $r->money_refunded_at !== null
                    && ! $r->fiscal_sign
                    && (float) $r->amount > 0,
                'bank_error' => $r->bank_error,
                'bank_response' => $r->bank_response,
                'attempts' => (int) ($r->legacy['attempts'] ?? 1),
                'station_annul_pending' => (bool) $r->station_annul_pending,
                'penalty_returned' => $withheld > 0 && $left <= 0,
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
            // Почему заказ в этом статусе: «Автостанция … недоступна»,
            // «Платёж не состоялся» и т.п. Без этого кассир видит только
            // слово failed и не понимает, что произошло.
            'status_reason' => $order->status_reason,
            /*
             * Фискальный признак чека ПОКУПКИ (он один на заказ). Его не
             * было видно нигде в карточке, хотя вопрос «пробит ли чек
             * продажи» возникает не реже, чем про чек возврата.
             */
            'fiscal_sign' => $order->fiscal_sign,
            'receipt_needed' => ! $order->fiscal_sign
                && $order->status === Order::STATUS_COMPLETED
                && (float) $order->total_price > 0,
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

        $info = $status['paymentAmountInfo'] ?? [];
        $amount = ((int) $status['amount']) / 100;
        $refunded = ((int) ($info['refundedAmount'] ?? 0)) / 100;
        $deposited = isset($info['depositedAmount']) ? ((int) $info['depositedAmount']) / 100 : null;

        /*
         * Срок с оплаты здесь НЕ ограничение: возврат (в отличие от отмены)
         * шлюз принимает и через дни — проверено 16.11.2026, когда возврат
         * удержания прошёл спустя 67 часов. Пять отказов до этого давала
         * не давность, а потерянная копейка: floor(8.95 * 100) = 894 при
         * доступных 895, то есть мы просили сумму, оставляющую одну
         * копейку «хвоста». Поэтому предупреждения про «прошло N часов»
         * здесь нет — оно вводило в заблуждение.
         */

        return [
            'order_status' => $code,
            'status_text' => self::ORDER_STATUS_TEXT[$code] ?? (string) $code,
            'action_code_description' => $status['actionCodeDescription'] ?? '',
            'amount' => $amount,
            'refunded' => $refunded,
            // Что по мнению шлюза ещё можно вернуть.
            'deposited' => $deposited,
            'available' => $deposited ?? round($amount - $refunded, 2),
            'payment_state' => $info['paymentState'] ?? null,
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

    /**
     * Билеты заказа и что с каждым сделано: возвращён ли, вернулись ли
     * деньги, есть ли чек и аннулирован ли бланк на станции.
     *
     * @return array<int, array<string, mixed>>
     */
    private function ticketsState(Order $order): array
    {
        $refunds = Refund::where('order_id', $order->id)
            ->where('kind', Refund::KIND_TICKET)
            ->get()
            ->keyBy(fn (Refund $r) => $r->serial.'/'.$r->number);

        /*
         * Состояние бланков в журнале станции — одним запросом на заказ.
         * Без него билет, аннулированный на станции без записи у нас,
         * показывался «действует»: ровно наоборот тому, что есть, и
         * кассир не видел ни того, что место свободно, ни того, что
         * деньги покупателю не ушли.
         */
        $stationBlanks = $this->stationBlanks($order);

        return $order->tickets()->orderBy('position')->get()->map(function (Ticket $t) use ($refunds, $stationBlanks) {
            $refund = $refunds->get($t->serial.'/'.$t->number);
            $station = $stationBlanks[$t->serial.'/'.$t->number] ?? null;
            $annulment = is_array($station['annulment'] ?? null) ? $station['annulment'] : null;

            return [
                'serial' => $t->serial,
                'number' => $t->number,
                'place' => (int) $t->place,
                'passenger' => trim($t->last_name.' '.$t->first_name.' '.($t->middle_name ?? '')),
                'price' => (float) $t->price,
                'baggage' => (int) $t->baggage,
                'price_bag' => (float) $t->price_bag,
                /*
                 * Полная стоимость бланка: место плюс багаж. В колонке
                 * «Цена» раньше стояла только цена места (141,74 при
                 * фактической стоимости 178,85) — кассир сравнивал её с
                 * суммой возврата и не понимал расхождения.
                 */
                'price_total' => round((float) $t->price + (float) $t->price_bag * (int) $t->baggage, 2),
                'refunded' => $refund !== null,
                'refund_id' => $refund?->id,
                'refund_amount' => $refund ? (float) $refund->amount : null,
                'refund_at' => $refund?->created_at?->format('d.m.Y H:i'),
                'money_refunded' => $refund?->money_refunded_at !== null,
                'fiscal_sign' => $refund?->fiscal_sign,
                // Деньги вернули, а на станции бланк ещё проданный: место
                // занято, аннулирование надо дослать.
                'station_annul_pending' => (bool) $refund?->station_annul_pending,
                // Что про этот бланк знает станция (null — она не ответила).
                'station_annulled' => $annulment === null ? null : true,
                'station_annulled_at' => $annulment === null
                    ? null
                    : trim(($annulment['date'] ?? '').' '.($annulment['time'] ?? '')),
                'station_annulled_by_our_agent' => $annulment === null
                    ? null
                    : (bool) ($annulment['by_our_agent'] ?? false),
                'station_agent_code' => $annulment['agent_code'] ?? null,
                'state' => match (true) {
                    /*
                     * На станции бланк аннулирован, а записи возврата у нас
                     * нет — значит деньги покупателю НЕ возвращались. Это то
                     * же ЧП, что «деньги у нас, билета нет», и выглядеть в
                     * списке оно должно именно так, а не «действует».
                     */
                    $refund === null && $annulment !== null => 'annulled_no_money',
                    $refund === null => 'active',
                    (bool) $refund->station_annul_pending => 'money_only',
                    $refund->money_refunded_at === null => 'bank_pending',
                    ! $refund->fiscal_sign => 'no_receipt',
                    default => 'refunded',
                },
            ];
        })->all();
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
            // Полная техника отказа шлюза: по короткому «Системная ошибка.»
            // сотруднику разобраться невозможно.
            'bank_response' => $refund->bank_response,
            'attempts' => (int) ($refund->legacy['attempts'] ?? 1),
            'id' => $refund->id,
            'kind' => $refund->kind,
            // Состояние на станции — независимо от денег.
            'station_annulled_at' => $refund->station_annulled_at?->format('d.m.Y H:i'),
            'station_annul_pending' => (bool) $refund->station_annul_pending,
            'station_annul_error' => $refund->station_annul_error,
            'station_annul_attempted_at' => $refund->station_annul_attempted_at?->format('d.m.Y H:i'),
            'station_backdated_at' => $refund->station_backdated_at?->format('d.m.Y H:i'),
        ];
    }

    /**
     * Возвраты, по которым станция ещё не знает об аннулировании: деньги
     * покупателю отданы, бланк на станции числится проданным.
     *
     * Это рабочий список раздела «Возвраты без станции» в АИС: пока он не
     * пуст, у станции заняты места под билеты, за которые уже вернули
     * деньги.
     *
     * @return array<int, array<string, mixed>>
     */
    public function pendingAnnulments(): array
    {
        return Refund::query()
            ->where('station_annul_pending', true)
            ->whereNull('station_annulled_at')
            ->with('order')
            ->orderBy('created_at')
            ->limit(200)
            ->get()
            ->map(fn (Refund $r) => [
                'refund_id' => $r->id,
                'order_id' => $r->order_id,
                'kind' => $r->kind,
                'serial' => $r->serial,
                'number' => $r->number,
                'place' => $r->place,
                'amount' => (float) $r->amount,
                'status' => $r->status,
                'money_refunded_at' => $r->money_refunded_at?->format('d.m.Y H:i'),
                'created_at' => $r->created_at?->format('d.m.Y H:i'),
                'attempted_at' => $r->station_annul_attempted_at?->format('d.m.Y H:i'),
                'error' => $r->station_annul_error,
                'awtcod' => $r->order?->awtcod,
                'station' => $r->order?->from_name,
                'trip' => trim((string) $r->order?->from_name.' → '.(string) $r->order?->to_name),
                'date_start' => $r->order?->date_start?->format('d.m.Y'),
                // Ссылка на карточку возврата — оттуда и досылают.
                'refund_url' => '/back-ticket?order_id='.$r->order_id
                    .'&serial='.$r->serial.'&number='.$r->number,
            ])->all();
    }

    /**
     * Состояние бланков заказа в журнале станции: продан, возвращён, кем.
     *
     * Своей таблицы `refunds` для этого НЕ достаточно. Во-первых, бланк
     * может быть аннулирован на станции без записи у нас (отказ по
     * другому бланку отменял нашу запись, но не аннулирование —
     * 17.11.2026). Во-вторых, бланк мог вернуть кассир станции своими
     * руками, и тогда деньги покупателю выданы там же — возврат на карту
     * был бы вторым.
     *
     * Станция не ответила — состояние просто неизвестно, и карточка
     * работает как раньше: это дополнительные сведения, а не условие.
     *
     * @return array<string, array<string, mixed>> ключ «серия/номер»
     */
    private function stationBlanks(Order $order): array
    {
        if (isset($this->stationBlanksCache[$order->id])) {
            return $this->stationBlanksCache[$order->id];
        }

        $blanks = [];

        foreach ($order->tickets as $ticket) {
            $blanks[] = ['serial' => (string) $ticket->serial, 'number' => (string) $ticket->number];

            foreach ($ticket->baggageBlanks() as $bag) {
                $blanks[] = ['serial' => (string) $bag['serial'], 'number' => (string) $bag['number']];
            }
        }

        if ($blanks === []) {
            return $this->stationBlanksCache[$order->id] = [];
        }

        try {
            $rows = $this->salesApi->blanks(
                (int) $order->awtcod,
                $blanks,
                $order->date_start?->format('Y-m-d'),
            )['data']['blanks'] ?? [];
        } catch (SalesApiException $e) {
            return $this->stationBlanksCache[$order->id] = [];
        }

        $map = [];

        foreach ($rows as $row) {
            $map[$row['serial'].'/'.$row['number']] = $row;
        }

        return $this->stationBlanksCache[$order->id] = $map;
    }

    /**
     * Операция возврата бланка в журнале станции, если она там есть.
     *
     * @return array<string, mixed>|null
     */
    private function stationAnnulment(Order $order, string $serial, string $number): ?array
    {
        $state = $this->stationBlanks($order)[$serial.'/'.$number] ?? null;

        return is_array($state['annulment'] ?? null) ? $state['annulment'] : null;
    }

    /**
     * Станция отказала в возврате — разобраться, почему, и сказать прямо.
     *
     * Прежде любой отказ давал один и тот же тупик: «Станция отказала»,
     * и дальше кассир ничего сделать не мог. Но за этим текстом стоят
     * РАЗНЫЕ случаи, и в одном из них деньги покупателя остаются у нас:
     *
     *  - бланк аннулирован НАШИМ агентом, записи возврата у нас нет —
     *    это потерянный возврат (ровно случай 17.11.2026). Деньги надо
     *    вернуть, аннулировать уже нечего;
     *  - бланк аннулировала КАССА станции — деньги, скорее всего, выданы
     *    там наличными. Возврат на карту был бы вторым, поэтому кнопки
     *    нет: сначала сверка с кассой;
     *  - бланк действует, а отказ про другое (ведомость закрыта, рейс
     *    отправлен) — как раньше.
     *
     * @return array<string, mixed>
     */
    private function stationRejectedState(Order $order, Ticket $ticket, SalesApiException $e): array
    {
        $rejected = array_values(array_filter(
            (array) ($e->details['blanks'] ?? []),
            fn ($b) => is_array($b) && ! ($b['ok'] ?? false),
        ));

        $common = [
            'station_error' => $e->getMessage(),
            'station_error_code' => $e->errorCode,
            // Какие именно бланки станция не приняла и почему.
            'rejected_blanks' => $rejected,
        ];

        $annulment = $this->stationAnnulment($order, (string) $ticket->serial, (string) $ticket->number);

        if ($annulment === null) {
            return $common + [
                'state' => 'station_rejected',
                'message' => 'Станция отказала в возврате: '.$e->getMessage()
                    .' Сам билет в журнале станции числится проданным, то есть дело не в нём. '
                    .'Проверьте список билетов и бланков заказа ниже. Если возврат всё же нужен, '
                    .'его оформляют на станции вручную: `admin_mode` снимает только нашу проверку, '
                    .'правила станции (закрытая ведомость, запрет возврата после отправления) '
                    .'он не обходит.',
            ];
        }

        $byOurAgent = (bool) ($annulment['by_our_agent'] ?? false);
        $when = trim(($annulment['date'] ?? '').' '.($annulment['time'] ?? ''));
        $percent = (int) ($annulment['penalty_percent'] ?? 0);

        return $common + [
            'state' => 'station_already_annulled',
            'station_annulment' => $annulment,
            'annulled_by_our_agent' => $byOurAgent,
            'trip_name' => $order->trip_name,
            'datetime_start' => null,
            // Процент станция уже посчитала при аннулировании — он и
            // рекомендуется, менять его кассир может как обычно.
            'recommended_percent' => in_array($percent, self::PERCENTS, true) ? $percent : -1,
            'variants' => array_map(fn (int $p) => [
                'percent' => $p,
                'amount' => $this->refundAmount($order, $ticket, $p),
            ], self::PERCENTS),
            'message' => $byOurAgent
                ? 'Билет на станции УЖЕ аннулирован нашим возвратом ('.$when.', удержание '
                    .$percent.'%), а записи о возврате и денег покупателю нет. '
                    .'Аннулировать больше нечего — остаётся вернуть деньги.'
                : 'Билет на станции аннулирован НЕ нами: код агента '
                    .($annulment['agent_code'] ?? '?').', '.$when.'. Скорее всего, возврат '
                    .'оформила касса станции и деньги покупателю выданы там — тогда возврат '
                    .'на карту будет вторым. Сверьтесь с кассой, прежде чем возвращать деньги.',
        ];
    }

    /**
     * Вернуть деньги по билету, который на станции уже аннулирован.
     *
     * Отличие от обычного возврата: на станцию не идём вовсе — там
     * аннулировать нечего. Отличие от возврата при недоступной станции:
     * досылать аннулирование тоже не надо, поэтому `station_annul_pending`
     * НЕ ставится, а `station_annulled_at` берётся из записи станции —
     * иначе отчёты показали бы дату, когда бланк аннулировали, неверно.
     *
     * @return array<string, mixed>
     */
    private function confirmAlreadyAnnulled(
        Order $order,
        Ticket $ticket,
        int $percent,
        bool $withReceipt,
    ): array {
        $annulment = $this->stationAnnulment($order, (string) $ticket->serial, (string) $ticket->number);

        /*
         * Проверяем по станции, а не на слово клиенту: этой кнопкой
         * обходится обычный путь, и обходить его можно только когда
         * аннулирование на станции действительно есть.
         */
        if ($annulment === null) {
            throw ValidationException::withMessages([
                'ticket' => 'В журнале станции нет аннулирования этого билета — '
                    .'выполните обычный возврат.',
            ]);
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
            // Дата из журнала станции, а не now(): бланк аннулирован тогда.
            'station_annulled_at' => $this->annulmentTime($annulment),
            'station_annul_pending' => false,
            'legacy' => [
                // Чем именно подтверждается аннулирование — чтобы потом
                // было видно, на основании чего вернули деньги.
                'station_annulment' => $annulment,
            ],
        ]);
        $order->touch();

        $bankError = $this->refundMoney($refund, $order, $withReceipt);

        SendRefundEmail::dispatch($refund->id)->onQueue('emails');

        Log::warning('Возврат денег по билету, аннулированному на станции ранее', [
            'order_id' => $order->id,
            'refund_id' => $refund->id,
            'awtcod' => $order->awtcod,
            'ticket' => $ticket->serial.'/'.$ticket->number,
            'amount' => $amount,
            'station_annulment' => $annulment,
        ]);

        return [
            'trip_name' => $order->trip_name,
            'datetime_start' => null,
            'amount' => $amount,
            'bank_error' => $bankError,
            'message' => 'Деньги возвращены. На станции билет был аннулирован ранее ('
                .trim(($annulment['date'] ?? '').' '.($annulment['time'] ?? '')).'), '
                .'место там уже свободно.',
        ];
    }

    /**
     * Время аннулирования из журнала станции.
     *
     * Часы станции и наши могут расходиться, а дата возврата попадает в
     * отчёты — поэтому берём её у станции, а не now(). Не разобрали —
     * лучше текущее время, чем пустое поле: пустое означает «ждёт
     * аннулирования», а бланк уже аннулирован.
     *
     * @param  array<string, mixed>  $annulment
     */
    private function annulmentTime(array $annulment): Carbon
    {
        $raw = trim(($annulment['date'] ?? '').' '.($annulment['time'] ?? ''));

        try {
            return $raw === '' ? now() : Carbon::parse($raw);
        } catch (\Throwable $e) {
            return now();
        }
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

            /*
             * Квитанция уже аннулирована в журнале станции, а записи у нас
             * нет: так бывает, когда отдельный возврат багажа дошёл до
             * станции, но запись у нас не появилась. Передавать такую
             * квитанцию процедуре нельзя — она откажет по ней, и ВОЗВРАТ
             * БИЛЕТА не пройдёт вовсе (17.11.2026 именно так и вышло).
             * Аннулировать её второй раз всё равно невозможно.
             */
            if ($this->stationAnnulment($order, (string) $bag['serial'], (string) $bag['number'])) {
                continue;
            }

            $blanks[] = $bag;
        }

        return $blanks;
    }
}
