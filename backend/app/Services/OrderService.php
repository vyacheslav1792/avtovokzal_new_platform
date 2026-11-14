<?php

namespace App\Services;

use App\Jobs\RegisterFiscalReceipt;
use App\Jobs\SendPurchaseEmail;
use App\Jobs\SendRegistrationInvite;
use App\Models\Order;
use App\Models\User;
use App\Support\PassengerName;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Voronkovich\SberbankAcquiring\OrderStatus;

class OrderService
{
    public function __construct(
        private readonly SalesApiClient $salesApi,
        private readonly PaymentGateway $payments,
        private readonly SavedPassengerService $savedPassengers,
    ) {}

    /**
     * Создать заказ: резерв мест в API продаж + регистрация оплаты в шлюзе.
     *
     * @return Order с payment_url для редиректа на шлюз
     */
    public function create(array $data, string $ip, ?User $user = null): Order
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

        // Детский тариф — только вместе с полным (правило станции, legacy
        // проверял его и на форме, и на сервере). Выключается конфигом:
        // config/sale_rules.php → CHILD_TARIFF_REQUIRES_ADULT.
        if (config('sale_rules.child_requires_adult')) {
            $adults = collect($data['passengers'])->filter(
                fn (array $p) => $this->tariffType($p) === 'П',
            )->count();
            $children = count($data['passengers']) - $adults;
            $perAdult = (int) config('sale_rules.max_children_per_adult');

            if ($children > 0 && ($adults === 0 || $children > $adults * $perAdult)) {
                throw ValidationException::withMessages([
                    'passengers' => (string) config('sale_rules.child_requires_adult_message'),
                ]);
            }
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
                'last_name' => $this->normalizeName($p['last_name']),
                'first_name' => $this->normalizeName($p['first_name']),
                'middle_name' => $this->normalizeName($p['middle_name'] ?? ''),
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
            // У авторизованного покупателя почта — из профиля: подменить её
            // запросом нельзя, иначе билеты уйдут не туда.
            'email' => $this->orderEmail($user, $data['email'] ?? null),
            'user_id' => $user?->id,
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

        if ($user) {
            $this->rememberPassengers($user, $data['passengers'], $passengers);
        }

        return $order;
    }

    /**
     * Пассажиры в профиль покупателя: отмеченные галочкой «Сохранить»
     * добавляются, у остальных лишь обновляется дата последней поездки
     * (по ней список в кабинете сортируется).
     *
     * Пишем данные ПОСЛЕ нормализации ($prepared) — в профиле должно
     * лежать ровно то, что уйдёт в билет. Сбой сохранения покупку не
     * рушит: заказ уже создан и оплачивается.
     *
     * @param  array  $input  пассажиры как их прислала форма (с save_to_profile)
     * @param  array  $prepared  они же после нормализации для билета
     */
    private function rememberPassengers(User $user, array $input, array $prepared): void
    {
        try {
            foreach (array_values($input) as $i => $passenger) {
                $data = $prepared[$i] ?? null;

                if (! $data) {
                    continue;
                }

                filter_var($passenger['save_to_profile'] ?? false, FILTER_VALIDATE_BOOLEAN)
                    ? $this->savedPassengers->remember($user, $data)
                    : $this->savedPassengers->touchUsed($user, [$data]);
            }
        } catch (\Throwable $e) {
            Log::warning('Не удалось сохранить пассажиров в профиль', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Подтверждение после возврата со шлюза: проверка оплаты,
     * оформление билетов, постановка чека и письма в очередь.
     */
    /**
     * Почта заказа. У покупателя из кабинета — из профиля, у гостя — из
     * формы. Но кабинет через VK может остаться вообще без почты: VK отдаёт
     * её не всегда, а требовать её на форме мы не стали. Тогда в заказ идёт
     * служебный адрес — он нужен фискальному чеку (54-ФЗ: электронный чек
     * надо куда-то отправить, пустой адрес ККТ не примет).
     */
    private function orderEmail(?User $user, ?string $input): string
    {
        $email = $user?->email ?: $input;

        return mb_strtolower($email ?: (string) config('site.fiscal_fallback_email'));
    }

    /**
     * Это служебный адрес для чеков, а не почта покупателя — наши письма
     * (билеты, приглашение в кабинет, уведомление о возврате) туда слать
     * нельзя: ящик общий и покупателю не принадлежит.
     */
    public static function isFiscalFallbackEmail(?string $email): bool
    {
        $fallback = (string) config('site.fiscal_fallback_email');

        return $fallback !== '' && mb_strtolower((string) $email) === mb_strtolower($fallback);
    }

    /**
     * Сколько держится бронь мест: `KS_BRONTIME_PLACE` вызывается с 24
     * минутами (`BusterGateway::reserve`). После этого места уходят обратно
     * в продажу, и оплачивать заказ уже бессмысленно.
     */
    private const RESERVATION_MINUTES = 24;

    /**
     * Насколько раньше отправления закрывается продажа через сайт —
     * те же 1920 секунд, что проверяет `create()`.
     */
    private const SALE_STOPS_BEFORE_SECONDS = 1920;

    /**
     * Незавершённые покупки: заказ создан, но билетов у покупателя нет.
     *
     * Случай из жизни: покупатель ушёл на страницу шлюза и закрыл её —
     * вернувшись на сайт, он не знает ни номера заказа, ни того, списались
     * ли деньги. В legacy это решалось баннером поверх страниц, а сама
     * незавершённая покупка жила в PHP-сессии и умирала вместе с ней.
     * Здесь заказы ищутся двумя путями: по идентификаторам, которые сайт
     * запомнил в браузере (переживают перезапуск), и по кабинету — у
     * авторизованного покупателя незавершённая покупка найдётся и с
     * другого устройства.
     *
     * @param  string[]  $ids  идентификаторы заказов из браузера
     * @return array<int, array<string, mixed>>
     */
    public function pending(array $ids, ?User $user = null): array
    {
        if ($ids === [] && ! $user) {
            return [];
        }

        $orders = Order::query()
            ->whereIn('status', [Order::STATUS_PAYMENT_REGISTERED, Order::STATUS_PAID])
            ->whereNotNull('sber_order_id')
            // Старьё не разбираем: бронь давно слетела, а шлюз закрыл заказ
            // по своему таймауту — спрашивать его незачем.
            ->where('created_at', '>=', now()->subHours(2))
            ->where(function ($query) use ($ids, $user) {
                if ($ids !== []) {
                    $query->whereIn('id', $ids);
                }
                if ($user) {
                    $query->orWhere('user_id', $user->id);
                }
            })
            ->latest()
            ->limit(5)
            ->get();

        return $orders
            ->map(fn (Order $order) => $this->pendingState($order))
            ->filter()
            ->values()
            ->all();
    }

    /**
     * Что покупателю делать с незавершённым заказом. null — ничего: заказ
     * уже не спасти (бронь слетела, шлюз заказ закрыл) либо шлюз недоступен
     * и обещать покупателю нечего.
     */
    private function pendingState(Order $order): ?array
    {
        $deadline = $this->payDeadline($order);

        if ($order->status === Order::STATUS_PAID) {
            // Деньги уже у нас, а билетов нет — оформление доделывается
            // всегда, даже если бронь формально истекла: решать судьбу
            // чужих денег молчанием нельзя.
            $state = 'awaiting_confirmation';
        } else {
            try {
                $gateway = (int) ($this->payments->statusExtended((string) $order->sber_order_id)['orderStatus'] ?? -1);
            } catch (\Throwable $e) {
                Log::warning('Не удалось узнать статус заказа в шлюзе', [
                    'order_id' => $order->id,
                    'error' => $e->getMessage(),
                ]);

                return null;
            }

            if ($gateway === OrderStatus::DEPOSITED) {
                $state = 'awaiting_confirmation';
            } elseif ($gateway === OrderStatus::CREATED && $deadline->isFuture()) {
                $state = 'awaiting_payment';
            } else {
                // Отменён, отклонён или бронь уже истекла — предлагать
                // нечего, пусть ищет рейс заново.
                return null;
            }
        }

        return [
            'order_id' => $order->id,
            'state' => $state,
            // Нужен странице /payment/result: она подтверждает оплату по
            // идентификатору заказа в шлюзе — как и при возврате с него.
            'sber_order_id' => $order->sber_order_id,
            'from_name' => $order->from_name,
            'to_name' => $order->to_name,
            'trip_name' => $order->trip_name,
            'date_start' => $order->date_start->format('Y-m-d'),
            'time_start' => substr((string) $order->time_start, 0, 5),
            'total_price' => (float) $order->total_price,
            'places' => collect($order->passengers)->pluck('place')->values()->all(),
            // Ссылка на шлюз — только пока по ней есть смысл платить.
            'payment_url' => $state === 'awaiting_payment' ? $order->payment_url : null,
            'pay_until' => $state === 'awaiting_payment' ? $deadline->toIso8601String() : null,
        ];
    }

    /**
     * До какого момента имеет смысл платить: пока держится бронь мест и пока
     * не закрылась продажа на рейс.
     */
    public function payDeadline(Order $order): Carbon
    {
        $reservationUntil = $order->created_at->copy()->addMinutes(self::RESERVATION_MINUTES);

        $departure = Carbon::parse(
            $order->date_start->format('Y-m-d').' '.$order->time_start,
            config('app.timezone'),
        )->subSeconds(self::SALE_STOPS_BEFORE_SECONDS);

        return $departure->lt($reservationUntil) ? $departure : $reservationUntil;
    }

    /**
     * Отказ покупателя от неоплаченного заказа.
     *
     * Главное здесь — **снять бронь**: без этого места висят занятыми до
     * конца брони (~24 минуты) и их не купит никто, хотя покупатель уже
     * передумал. Заодно отменяем регистрацию платежа в шлюзе, чтобы его
     * ссылка перестала работать.
     *
     * Оплаченный заказ отменить нельзя: деньги уже у нас, такой заказ надо
     * либо дооформить (`confirm`), либо возвращать через `refunds`.
     */
    public function cancel(Order $order): Order
    {
        if (in_array($order->status, [Order::STATUS_CANCELLED, Order::STATUS_FAILED], true)) {
            return $order; // идемпотентность: повторный клик по «Отменить»
        }

        if (! in_array($order->status, [Order::STATUS_PENDING, Order::STATUS_PAYMENT_REGISTERED], true)) {
            throw new SalesApiException('Оплаченный заказ отменить нельзя — его нужно оформить или вернуть', 'order_paid');
        }

        // Статус из шлюза важнее нашего: деньги могли списаться минуту назад,
        // а возврата со шлюза мы ещё не видели.
        if ($order->sber_order_id && $this->payments->isPaid($order->sber_order_id)) {
            $order->update(['status' => Order::STATUS_PAID, 'paid_at' => now()]);

            throw new SalesApiException('Заказ уже оплачен — его нужно оформить, а не отменять', 'order_paid');
        }

        if ($order->reservation_id) {
            try {
                $this->salesApi->cancelReservation((string) $order->reservation_id);
            } catch (\Throwable $e) {
                // Бронь всё равно истечёт сама — отказ покупателя из-за этого
                // ронять не станем, но знать об этом надо.
                Log::warning('Не удалось снять бронь мест', [
                    'order_id' => $order->id,
                    'reservation_id' => $order->reservation_id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if ($order->sber_order_id) {
            try {
                $this->payments->reverse($order->sber_order_id);
            } catch (\Throwable $e) {
                // Неоплаченный заказ шлюз закроет сам по таймауту.
                Log::info('Шлюз не принял отмену заказа', [
                    'order_id' => $order->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $order->update([
            'status' => Order::STATUS_CANCELLED,
            'status_reason' => 'Покупатель отказался от оплаты',
        ]);

        return $order->refresh();
    }

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

        DB::transaction(function () use ($order, $sale) {
            // gender сайт хранит только в passengers (API продаж его не возвращает).
            $genders = collect($order->passengers)->keyBy(fn (array $p) => (string) $p['place']);

            foreach (array_values($sale['tickets']) as $i => $t) {
                $order->tickets()->create([
                    'position' => $i,
                    'serial' => $t['serial'] ?? null,
                    'number' => $t['number'] ?? null,
                    'place' => (int) $t['place'],
                    'type' => $t['type'] ?? 'П',
                    'price' => $t['price'],
                    'baggage' => (int) ($t['baggage'] ?? 0),
                    'price_bag' => $t['price_bag'] ?? 0,
                    'last_name' => $t['last_name'],
                    'first_name' => $t['first_name'],
                    'middle_name' => $t['middle_name'] ?? null,
                    'birth_date' => $t['birth_date'],
                    'gender' => $genders[(string) $t['place']]['gender'] ?? null,
                    'doc_type' => (int) ($t['doc_type'] ?? 21),
                    'doc_series' => $t['doc_series'] ?? null,
                    'doc_number' => $t['doc_number'],
                    'citizenship' => (int) ($t['citizenship'] ?? 185),
                    'comment' => $t['comment'] ?? null,
                    'baggage_tickets' => $t['baggage_tickets'] ?? [],
                ]);
            }

            $order->update([
                'back_code' => $this->generateBackCode(),
                'status' => Order::STATUS_COMPLETED,
                'completed_at' => now(),
            ]);
        });

        // Поток чека и письма — в очередь; обработчик пока не запускаем.
        RegisterFiscalReceipt::dispatch($order->id)->onQueue('receipts');
        SendPurchaseEmail::dispatch($order->id)->onQueue('emails');

        if ($this->isFirstPurchaseOfGuest($order)) {
            SendRegistrationInvite::dispatch($order->email)->onQueue('emails');
        }

        return $order->refresh();
    }

    /**
     * Первая покупка гостя: заказ сделан без входа, пользователя с таким
     * e-mail нет и других оформленных заказов на этот адрес тоже нет —
     * значит покупателю есть смысл предложить завести кабинет.
     */
    private function isFirstPurchaseOfGuest(Order $order): bool
    {
        if (self::isFiscalFallbackEmail($order->email)) {
            return false;
        }

        if ($order->user_id || User::where('email', $order->email)->exists()) {
            return false;
        }

        return ! Order::where('email', $order->email)
            ->where('id', '!=', $order->id)
            ->where('status', Order::STATUS_COMPLETED)
            ->exists();
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

    /** Месяцы в родительном падеже — legacy `rusmonth($n, 2)` (inc/config.php). */
    private const MONTHS = [
        1 => 'января', 'февраля', 'марта', 'апреля', 'мая', 'июня',
        'июля', 'августа', 'сентября', 'октября', 'ноября', 'декабря',
    ];

    /**
     * Описание платежа для шлюза — строка на каждое место, перенос
     * `avtovokzal57/url/passengers.php:395-409`:
     * «Билет {откуда} – {куда}, {дд} {месяц} {гггг} в {чч:мм}, м. {место}; багаж: {N} шт.»
     * Тире — U+2013, месяц строчными в родительном падеже (rusmonth mode=2),
     * багаж пишется всегда, даже когда мест ноль. Строки склеиваются через
     * CRLF, вся строка режется до 128 символов в PaymentGateway::register
     * (legacy `mb_substr($desctiption_sbrf, 0, 128)`).
     *
     * Слагаемое «; страховка» из legacy не переносим: новый сайт добровольную
     * страховку не продаёт (см. AdminRefundService).
     */
    private function paymentDescription(Order $order): string
    {
        $lines = [];
        foreach ($order->passengers as $p) {
            $bag = '; багаж: '.($p['baggage'] ?? 0).' шт.';
            $lines[] = sprintf(
                'Билет %s – %s, %s в %s, м. %s%s',
                $order->from_name,
                $order->to_name,
                $order->date_start->format('d').' '
                    .self::MONTHS[(int) $order->date_start->format('n')].' '
                    .$order->date_start->format('Y'),
                substr((string) $order->time_start, 0, 5),
                $p['place'],
                $bag,
            );
        }

        return implode("\r\n", $lines);
    }

    /**
     * ФИО для билета: верхний регистр + Ё→Е (известный баг вендорного
     * софта: буква Ё ломает обработку в АРМ станции). Правило общее с
     * сохранёнными пассажирами кабинета — живёт в PassengerName.
     */
    private function normalizeName(string $value): string
    {
        return PassengerName::normalize($value);
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
