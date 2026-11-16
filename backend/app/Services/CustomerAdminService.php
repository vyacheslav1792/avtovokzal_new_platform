<?php

namespace App\Services;

use App\Models\CustomerAdminAction;
use App\Models\Order;
use App\Models\Refund;
use App\Models\User;
use App\Models\UserIdentity;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Покупатели сайта для АИС: список кабинетов, карточка, помощь со входом
 * и вход в кабинет от имени покупателя.
 *
 * В legacy этого не было вовсе: АИС работала без авторизации, а база
 * покупателей (site.USERS) правилась запросами к Firebird руками — и
 * пароль там лежал открытым текстом (PASSWORD_RAW), так что «помощь со
 * входом» означала продиктовать покупателю его собственный пароль.
 *
 * ПОКУПАВШИЕ БЕЗ РЕГИСТРАЦИИ показываются в том же списке «гостями»:
 * поддержке звонят по покупке, а не по кабинету, и человек не знает,
 * регистрировался он или нет. Их всего несколько десятков адресов
 * (перенос legacy связал заказы с кабинетами по e-mail), поэтому список
 * собирается живым запросом — ничего материализовать не нужно.
 */
class CustomerAdminService
{
    private const PER_PAGE = 25;

    /**
     * Подписи статусов заказа. Формулировки — те же, что в отчёте АИС
     * «Продажи сайта» (ais-api SiteSalesReport::STATUS_LABELS): один и тот
     * же статус не должен называться в двух разделах по-разному.
     * `failed` с заполненным paid_at — другое дело: деньги списаны, а
     * билетов нет, и карточка говорит об этом прямо.
     */
    private const STATUS_LABELS = [
        'pending' => 'Создан, не оплачен',
        'payment_registered' => 'Ожидает оплаты',
        'paid' => 'Оплачен, оформление не завершено',
        'completed' => 'Куплен',
        'failed' => 'Платёж не состоялся',
        'cancelled' => 'Отменён',
    ];

    /** Сколько заказов и возвратов показываем в карточке. */
    private const CARD_ORDERS = 20;

    /**
     * Сколько живёт одноразовый код входа в кабинет. Коротко: сотрудник
     * нажимает кнопку и сразу переходит по ссылке, а код уходит в адресную
     * строку браузера.
     */
    private const IMPERSONATE_CODE_TTL = 120;

    /* ────────────────────────── Список ────────────────────────── */

    /**
     * Кабинеты и гости одним списком, страницами.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function list(array $filters): array
    {
        $page = max(1, (int) ($filters['page'] ?? 1));
        $kind = $filters['kind'] ?? 'all';

        [$where, $bindings] = $this->userConditions($filters);
        [$guestWhere, $guestBindings] = $this->guestConditions($filters);

        $parts = [];
        $params = [];

        if ($kind !== 'guests') {
            $parts[] = $this->usersSelect($where);
            $params = array_merge($params, $bindings);
        }

        if ($kind !== 'users') {
            $parts[] = $this->guestsSelect($guestWhere);
            $params = array_merge($params, $guestBindings);
        }

        if ($parts === []) {
            return ['items' => [], 'total' => 0, 'page' => 1, 'pages' => 1, 'counts' => $this->counts()];
        }

        $union = implode(' union all ', $parts);

        /*
         * Считаем не `count(*)` по объединённой выборке, а сумму двух
         * счётчиков: иначе тот же union прогонялся бы дважды — на подсчёт
         * и на выдачу страницы, и открытие списка стоило больше секунды.
         */
        $total = 0;

        if ($kind !== 'guests') {
            $total += (int) (DB::selectOne("select count(*) as total from users u where $where", $bindings)->total ?? 0);
        }

        if ($kind !== 'users') {
            $total += (int) (DB::selectOne(
                'select count(*) as total from ('.$this->guestsSelect($guestWhere).') g',
                $guestBindings,
            )->total ?? 0);
        }

        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        $page = min($page, $pages);

        $rows = DB::select(
            "select * from ($union) s order by s.sort_at desc nulls last, s.email asc nulls last "
                .'limit '.self::PER_PAGE.' offset '.(($page - 1) * self::PER_PAGE),
            $params,
        );

        return [
            'items' => $this->withOrderStats($rows),
            'total' => $total,
            'page' => $page,
            'pages' => $pages,
            'counts' => $this->counts(),
        ];
    }

    /**
     * Общие счётчики для вкладок списка. Кэш на минуту: цифры нужны
     * приблизительные («сколько всего кабинетов»), а считаются они по всей
     * базе покупателей и заказов.
     */
    private function counts(): array
    {
        return Cache::remember('admin:customers:counts', 60, fn () => $this->countsFresh());
    }

    private function countsFresh(): array
    {
        $users = (int) DB::table('users')->count();
        $vk = (int) DB::table('user_identities')->where('provider', UserIdentity::PROVIDER_VK)->distinct()->count('user_id');
        $guests = (int) (DB::selectOne(
            'select count(*) as total from (select lower(o.email) as email from orders o
               where o.user_id is null and o.email is not null and lower(o.email) <> ? group by 1) g
             where not exists (select 1 from users u where lower(u.email) = g.email)',
            [$this->fallbackEmail()],
        )->total ?? 0);

        return ['users' => $users, 'guests' => $guests, 'vk' => $vk, 'all' => $users + $guests];
    }

    /**
     * Строки кабинетов — ТОЛЬКО то, что лежит в колонках.
     *
     * Ничего считаемого (способы входа, число покупок) здесь быть не
     * должно: выражения в select вычисляются до сортировки и `limit`, то
     * есть для всех 84 тысяч кабинетов. Подзапрос способов входа в этом
     * месте стоил полсекунды на каждое открытие списка — теперь и они, и
     * счётчики покупок дописываются к показанным 25 строкам.
     */
    private function usersSelect(string $where): string
    {
        return "select 'user' as kind,
                   u.id,
                   u.email,
                   u.name,
                   u.phone,
                   u.email_verified_at is not null as email_verified,
                   u.password is not null or u.legacy_password is not null as has_password,
                   u.legacy_password is not null as legacy_only,
                   u.created_at,
                   u.last_login_at,
                   u.created_at as sort_at
            from users u
            where $where";
    }

    /**
     * Строки гостей: адреса, на которые покупали без кабинета. Служебный
     * адрес фискальных чеков исключён — он общий, и по нему сошлись бы
     * покупки разных людей (см. OrderService::isFiscalFallbackEmail).
     */
    private function guestsSelect(string $where): string
    {
        return "select 'guest' as kind,
                   null::bigint as id,
                   g.email,
                   null::varchar as name,
                   g.phone,
                   false as email_verified,
                   false as has_password,
                   false as legacy_only,
                   g.first_order_at as created_at,
                   null::timestamptz as last_login_at,
                   g.last_order_at as sort_at
            from (
                select lower(o.email) as email,
                       min(o.created_at) as first_order_at,
                       max(o.created_at) as last_order_at,
                       max(o.phone) as phone
                from orders o
                where o.user_id is null and o.email is not null and lower(o.email) <> ?
                group by 1
            ) g
            where not exists (select 1 from users u where lower(u.email) = g.email)
              and $where";
    }

    /**
     * Условия по кабинетам.
     *
     * @return array{0: string, 1: array<int, mixed>}
     */
    private function userConditions(array $filters): array
    {
        $where = ['true'];
        $bind = [];

        if ($q = $this->query($filters)) {
            $parts = ['lower(u.email) like ?', 'lower(u.name) like ?'];
            $bind[] = $q['like'];
            $bind[] = $q['like'];

            if ($q['digits'] !== null) {
                $parts[] = "regexp_replace(coalesce(u.phone, ''), '[^0-9]', '', 'g') like ?";
                $bind[] = '%'.$q['digits'].'%';
            }

            if ($q['id'] !== null) {
                $parts[] = 'u.id = ?';
                $bind[] = $q['id'];
            }

            $where[] = '('.implode(' or ', $parts).')';
        }

        // Способ входа. «Старый пароль» — кабинет со старого сайта, который
        // ещё ни разу не входил: его md5 лежит в legacy_password и гаснет
        // при первом успешном входе.
        $login = $filters['login'] ?? null;

        if ($login === 'vk') {
            $where[] = 'exists (select 1 from user_identities i where i.user_id = u.id and i.provider = ?)';
            $bind[] = UserIdentity::PROVIDER_VK;
        } elseif ($login === 'password') {
            $where[] = 'u.password is not null';
        } elseif ($login === 'legacy') {
            $where[] = 'u.legacy_password is not null';
        } elseif ($login === 'none') {
            // Войти нечем вовсе: ни пароля, ни привязки. Такой кабинет
            // остаётся, например, после отвязки VK — покупатель внутрь
            // не попадёт, и это повод им заняться.
            $where[] = 'u.password is null and u.legacy_password is null and not exists '
                .'(select 1 from user_identities i where i.user_id = u.id and i.provider <> ?)';
            $bind[] = UserIdentity::PROVIDER_PASSWORD;
        }

        if (($filters['has_orders'] ?? null) === '1') {
            $where[] = $this->userHasOrdersSql();
            $bind[] = $this->fallbackEmail();
        } elseif (($filters['has_orders'] ?? null) === '0') {
            $where[] = 'not '.$this->userHasOrdersSql();
            $bind[] = $this->fallbackEmail();
        }

        if (! empty($filters['email_unverified'])) {
            $where[] = 'u.email_verified_at is null';
        }

        if (! empty($filters['date_from'])) {
            $where[] = 'u.created_at >= ?';
            $bind[] = $filters['date_from'].' 00:00:00';
        }

        if (! empty($filters['date_to'])) {
            $where[] = 'u.created_at <= ?';
            $bind[] = $filters['date_to'].' 23:59:59';
        }

        return [implode(' and ', $where), $bind];
    }

    /**
     * Есть ли у кабинета покупки — свои и сделанные на его адрес до
     * регистрации.
     *
     * Второе условие — множеством, а не коррелированным exists: заказов
     * без кабинета в базе считанные сотни (перенос legacy связал покупки с
     * кабинетами по адресу), поэтому подзапрос считается один раз, а не на
     * каждую из 84 тысяч строк — так фильтр стал дешевле почти в десять раз.
     */
    private function userHasOrdersSql(): string
    {
        return '(exists (select 1 from orders o where o.user_id = u.id)
                 or lower(u.email) in (select lower(o2.email) from orders o2
                                       where o2.user_id is null and o2.email is not null
                                         and lower(o2.email) <> ?))';
    }

    /**
     * Условия по гостям. Фильтры, у которых нет смысла без кабинета
     * (способ входа, подтверждение адреса), гостей просто отсекают —
     * иначе список показывал бы их в выборке «вход через VK».
     *
     * @return array{0: string, 1: array<int, mixed>}
     */
    private function guestConditions(array $filters): array
    {
        $bind = [$this->fallbackEmail()];

        if (($filters['login'] ?? null) !== null || ! empty($filters['email_unverified'])
            || ($filters['has_orders'] ?? null) === '0') {
            return ['false', $bind];
        }

        $where = ['true'];

        if ($q = $this->query($filters)) {
            $parts = ['g.email like ?'];
            $bind[] = $q['like'];

            if ($q['digits'] !== null) {
                $parts[] = "regexp_replace(coalesce(g.phone, ''), '[^0-9]', '', 'g') like ?";
                $bind[] = '%'.$q['digits'].'%';
            }

            $where[] = '('.implode(' or ', $parts).')';
        }

        if (! empty($filters['date_from'])) {
            $where[] = 'g.first_order_at >= ?';
            $bind[] = $filters['date_from'].' 00:00:00';
        }

        if (! empty($filters['date_to'])) {
            $where[] = 'g.first_order_at <= ?';
            $bind[] = $filters['date_to'].' 23:59:59';
        }

        return [implode(' and ', $where), $bind];
    }

    /**
     * Разбор строки поиска: адрес и имя — подстрокой, телефон — по цифрам
     * (в базе он нормализован, +7 (920) 123-45-67 набирают как угодно),
     * число — ещё и номером кабинета.
     *
     * @return array{like: string, digits: ?string, id: ?int}|null
     */
    private function query(array $filters): ?array
    {
        $q = trim((string) ($filters['q'] ?? ''));

        if ($q === '') {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $q);

        return [
            'like' => '%'.mb_strtolower($q).'%',
            // Меньше пяти цифр — это не номер телефона, а часть имени или
            // адреса: по '%7%' нашлась бы половина базы.
            'digits' => strlen((string) $digits) >= 5 ? $digits : null,
            'id' => ctype_digit($q) && strlen($q) <= 9 ? (int) $q : null,
        ];
    }

    /**
     * Счётчики покупок дописываем ТОЛЬКО к показанной странице: агрегат по
     * всей таблице заказов (миллион строк) ради 25 строк списка не нужен.
     */
    private function withOrderStats(array $rows): array
    {
        $ids = [];
        $emails = [];

        foreach ($rows as $row) {
            if ($row->id !== null) {
                $ids[] = (int) $row->id;
            }

            if ($row->email !== null) {
                $emails[] = mb_strtolower($row->email);
            }
        }

        $byUser = $ids === [] ? [] : collect(DB::select(
            'select user_id,
                    count(*) as total,
                    count(*) filter (where status = ?) as completed,
                    max(created_at) as last_order_at
             from orders where user_id in ('.$this->placeholders($ids).') group by 1',
            array_merge([Order::STATUS_COMPLETED], $ids),
        ))->keyBy('user_id')->all();

        /*
         * Покупки без кабинета: у гостя это все его заказы, у кабинета —
         * сделанные на его адрес до регистрации.
         *
         * ЛОВУШКА: адреса подставляем ТОЛЬКО параметрами. Литерал массива
         * Postgres (`= any('{a,b}')`) здесь не годится — в базе лежат
         * адреса с кавычками, слэшами и переводами строк (со старого сайта
         * приехали попытки инъекций в поле e-mail), и запрос падал на
         * «malformed array literal».
         */
        $byEmail = $emails === [] ? [] : collect(DB::select(
            'select lower(email) as email,
                    count(*) as total,
                    count(*) filter (where status = ?) as completed,
                    max(created_at) as last_order_at
             from orders where user_id is null and lower(email) in ('.$this->placeholders($emails).') group by 1',
            array_merge([Order::STATUS_COMPLETED], $emails),
        ))->keyBy('email')->all();

        $providers = $ids === [] ? [] : collect(DB::select(
            "select user_id, string_agg(distinct provider, ',' order by provider) as providers
             from user_identities where user_id in (".$this->placeholders($ids).') group by 1',
            $ids,
        ))->keyBy('user_id')->all();

        return array_map(function ($row) use ($byUser, $byEmail, $providers) {
            $user = $row->id !== null ? ($byUser[$row->id] ?? null) : null;
            $guest = $row->email !== null ? ($byEmail[mb_strtolower($row->email)] ?? null) : null;

            $total = (int) ($user->total ?? 0) + (int) ($guest->total ?? 0);
            $completed = (int) ($user->completed ?? 0) + (int) ($guest->completed ?? 0);
            $last = collect([$user->last_order_at ?? null, $guest->last_order_at ?? null])
                ->filter()->max();

            return [
                'kind' => $row->kind,
                'id' => $row->id === null ? null : (int) $row->id,
                'email' => $row->email,
                'name' => $row->name,
                'phone' => $row->phone,
                'email_verified' => (bool) $row->email_verified,
                'has_password' => (bool) $row->has_password,
                'providers' => $row->id !== null && isset($providers[$row->id])
                    ? explode(',', $providers[$row->id]->providers)
                    : [],
                'legacy_only' => (bool) $row->legacy_only,
                'orders_total' => $total,
                'orders_completed' => $completed,
                'last_order_at' => $last ? $this->date($last) : null,
                'created_at' => $row->created_at ? $this->date($row->created_at) : null,
                'last_login_at' => $row->last_login_at ? $this->date($row->last_login_at) : null,
            ];
        }, $rows);
    }

    /* ────────────────────────── Карточка ────────────────────────── */

    /** @return array<string, mixed> */
    public function card(User $user): array
    {
        $orders = $user->orders()->orderByDesc('created_at');

        $stats = DB::selectOne(
            'select count(*) as total,
                    count(*) filter (where status = ?) as completed,
                    coalesce(sum(total_price) filter (where status = ?), 0) as amount,
                    min(created_at) as first_order_at,
                    max(created_at) as last_order_at
             from ('.$orders->toSql().') o',
            array_merge([Order::STATUS_COMPLETED, Order::STATUS_COMPLETED], $orders->getBindings()),
        );

        return [
            'id' => $user->id,
            'email' => $user->email,
            'phone' => $user->phone,
            'name' => $user->name,
            'email_verified' => (bool) $user->email_verified_at,
            'phone_verified' => (bool) $user->phone_verified_at,
            'has_password' => $user->hasPassword(),
            // Пароль ещё со старого сайта: покупатель с тех пор не входил.
            'legacy_password' => (bool) $user->legacy_password,
            'created_at' => $this->date($user->created_at),
            'last_login_at' => $user->last_login_at ? $this->date($user->last_login_at) : null,
            'identities' => $this->identities($user),
            'stats' => [
                'orders' => (int) $stats->total,
                'completed' => (int) $stats->completed,
                'amount' => (float) $stats->amount,
                'first_order_at' => $stats->first_order_at ? $this->date($stats->first_order_at) : null,
                'last_order_at' => $stats->last_order_at ? $this->date($stats->last_order_at) : null,
                'refunds' => $this->refundsQuery($user)->count(),
            ],
            'orders' => $this->orderRows((clone $orders)->limit(self::CARD_ORDERS)->get()),
            'refunds' => $this->refundRows($user),
            'passengers' => $this->passengers($user),
            'sessions' => $this->sessions($user),
            'actions' => $this->actions($user),
        ];
    }

    /**
     * Карточка покупавшего без кабинета: заказы по адресу. Действий у неё
     * нет — менять нечего, кабинета не существует.
     *
     * @return array<string, mixed>
     */
    public function guestCard(string $email): array
    {
        $email = mb_strtolower(trim($email));

        if ($email === $this->fallbackEmail()) {
            throw ValidationException::withMessages([
                'email' => 'Это служебный адрес для фискальных чеков, а не покупатель: '
                    .'по нему сходятся чеки разных людей.',
            ]);
        }

        $orders = Order::query()
            ->whereNull('user_id')
            ->whereRaw('lower(email) = ?', [$email])
            ->orderByDesc('created_at');

        $rows = (clone $orders)->limit(self::CARD_ORDERS)->get();
        $all = (clone $orders)->get(['id', 'status', 'total_price', 'created_at', 'phone']);

        return [
            'kind' => 'guest',
            'email' => $email,
            'phone' => $all->firstWhere('phone', '!=', null)?->phone,
            'user_exists' => User::whereRaw('lower(email) = ?', [$email])->exists(),
            'stats' => [
                'orders' => $all->count(),
                'completed' => $all->where('status', Order::STATUS_COMPLETED)->count(),
                'amount' => (float) $all->where('status', Order::STATUS_COMPLETED)->sum('total_price'),
                'first_order_at' => $all->min('created_at') ? $this->date($all->min('created_at')) : null,
                'last_order_at' => $all->max('created_at') ? $this->date($all->max('created_at')) : null,
                'refunds' => Refund::whereIn('order_id', $all->pluck('id'))->count(),
            ],
            'orders' => $this->orderRows($rows),
        ];
    }

    /** Способы входа: пароль, VK, в будущем телефон. */
    private function identities(User $user): array
    {
        return $user->identities()->orderBy('provider')->get()->map(fn (UserIdentity $i) => [
            'provider' => $i->provider,
            // У VK это числовой id аккаунта, у пароля — e-mail-логин.
            'provider_user_id' => $i->provider_user_id,
            'name' => $i->provider === UserIdentity::PROVIDER_VK
                ? trim(($i->payload['first_name'] ?? '').' '.($i->payload['last_name'] ?? '')) ?: null
                : null,
            'created_at' => $i->created_at ? $this->date($i->created_at) : null,
            'last_login_at' => $i->last_login_at ? $this->date($i->last_login_at) : null,
        ])->all();
    }

    /** Заказы для карточки: ссылки — на печать билета и на возврат в АИС. */
    private function orderRows($orders): array
    {
        return $orders->map(fn (Order $o) => [
            'id' => $o->id,
            'created_at' => $this->date($o->created_at),
            'date_start' => $o->date_start?->format('d.m.Y'),
            'time_start' => mb_substr((string) $o->time_start, 0, 5),
            'route' => $o->from_name.' → '.$o->to_name,
            'total_price' => (float) $o->total_price,
            'status' => $o->status,
            'status_title' => $o->status === Order::STATUS_FAILED && $o->paid_at
                ? 'Деньги списаны, билеты не оформлены'
                : (self::STATUS_LABELS[$o->status] ?? $o->status),
            'tickets' => $o->tickets()->count(),
            'email' => $o->email,
            'legacy' => $o->legacy !== null,
            'print_url' => $o->status === Order::STATUS_COMPLETED
                ? rtrim(config('site.url'), '/').'/print/'.$o->id
                : null,
            'refund_url' => '/back-ticket?order_id='.$o->id,
        ])->all();
    }

    private function refundsQuery(User $user)
    {
        return Refund::whereIn('order_id', $user->orders()->select('id'));
    }

    private function refundRows(User $user): array
    {
        return $this->refundsQuery($user)
            ->orderByDesc('created_at')
            ->limit(self::CARD_ORDERS)
            ->get()
            ->map(fn (Refund $r) => [
                'id' => $r->id,
                'order_id' => $r->order_id,
                'kind' => $r->kind,
                'serial' => $r->serial,
                'number' => $r->number,
                'amount' => (float) $r->amount,
                'status' => $r->status,
                'created_at' => $this->date($r->created_at),
                'refund_url' => '/back-ticket?order_id='.$r->order_id
                    .'&serial='.$r->serial.'&number='.$r->number,
            ])->all();
    }

    /**
     * Сохранённые пассажиры — БЕЗ номеров документов: для поддержки важно,
     * кто сохранён, а номер паспорта в карточке незачем (он есть в билете,
     * куда ведёт отдельная ссылка).
     */
    private function passengers(User $user): array
    {
        return $user->savedPassengers()
            ->orderByDesc('last_used_at')
            ->get()
            ->map(fn ($p) => [
                'id' => $p->id,
                'name' => $p->fullName(),
                'doc_type' => $p->doc_type,
                'last_used_at' => $p->last_used_at ? $this->date($p->last_used_at) : null,
            ])->all();
    }

    /**
     * Активные сессии кабинета (Bearer-токены Sanctum): сколько устройств
     * и когда ими пользовались. Сам токен не показываем — по нему можно
     * войти.
     */
    private function sessions(User $user): array
    {
        $idle = (int) config('site.token_idle_minutes');

        return $user->tokens()
            ->orderByDesc('last_used_at')
            ->get()
            ->map(fn ($t) => [
                'id' => $t->id,
                'name' => $t->name,
                'impersonated' => \App\Support\ImpersonationToken::is($t),
                'created_at' => $this->date($t->created_at),
                'last_used_at' => $t->last_used_at ? $this->date($t->last_used_at) : null,
                'alive' => ($t->last_used_at ?? $t->created_at)?->gt(now()->subMinutes($idle)) ?? false,
            ])->all();
    }

    private function actions(User $user): array
    {
        return CustomerAdminAction::where('user_id', $user->id)
            ->orderByDesc('created_at')
            ->limit(50)
            ->get()
            ->map(fn (CustomerAdminAction $a) => [
                'action' => $a->action,
                'title' => $a->title(),
                'employee' => $a->employee_name ?: $a->employee_login,
                'details' => $a->details,
                'created_at' => $this->date($a->created_at),
            ])->all();
    }

    /* ────────────────────────── Действия ────────────────────────── */

    /**
     * Пароль кабинета: либо задаём тот, который ввёл сотрудник, либо
     * гасим вовсе (пустое значение) — тогда покупатель задаёт пароль сам
     * через «Забыли пароль».
     *
     * Пароль, введённый сотрудником, принимается КАК ЕСТЬ: ни длины, ни
     * состава знаков, ни стойкости не проверяем. Это сознательное решение
     * — пароль придумывает человек, который говорит с покупателем по
     * телефону, и требование «не меньше восьми знаков с цифрой» здесь
     * только мешает (у самого покупателя на сайте минимум 8 остаётся).
     * `trim` обязателен и здесь, и при проверке — пробел легко прилетает
     * из буфера обмена (так же поступает AuthService).
     *
     * Сам пароль в журнал НЕ пишем — только отметку, задан он или сброшен.
     */
    public function resetPassword(
        User $user,
        AisEmployee $employee,
        ?string $password = null,
        ?string $ip = null,
    ): array {
        $password = trim((string) $password);
        $assigned = $password !== '';

        $user->forceFill([
            'password' => $assigned ? $password : null,
            // Хэш со старого сайта гасим в любом случае: иначе прежний
            // пароль продолжал бы пускать в кабинет (ровно эту дыру
            // legacy и имел — вход сверял `password` ИЛИ `password_raw`).
            'legacy_password' => null,
        ])->save();

        // Сессии гасим: пароль меняют и когда в кабинет попал посторонний,
        // оставлять его токены нельзя.
        $killed = $user->tokens()->delete();

        CustomerAdminAction::record(
            $employee,
            CustomerAdminAction::ACTION_RESET_PASSWORD,
            $user,
            details: ['sessions_closed' => $killed, 'assigned' => $assigned],
            ip: $ip,
        );

        if ($assigned) {
            return [
                'message' => 'Пароль задан. Передайте его покупателю — вход по e-mail'
                    .($user->email ? ' '.$user->email : '').' и этому паролю. '
                    .'Все прежние сессии закрыты.',
                'sessions_closed' => $killed,
            ];
        }

        return [
            'message' => $user->email
                ? 'Пароль сброшен. Покупатель задаёт новый сам: «Войти» → «Забыли пароль» — '
                    .'код придёт на '.$user->email.'.'
                : 'Пароль сброшен. У кабинета нет e-mail, поэтому задать пароль можно только '
                    .'после добавления адреса — вход пока остаётся через VK.',
            'sessions_closed' => $killed,
        ];
    }

    /**
     * Подтвердить адрес вручную. Это не косметика: по подтверждённому
     * адресу кабинету видны покупки, сделанные на него до регистрации, —
     * поэтому ставим отметку только когда сотрудник действительно
     * убедился, что адрес принадлежит покупателю.
     */
    public function verifyEmail(User $user, AisEmployee $employee, ?string $ip = null): array
    {
        if (! $user->email) {
            throw ValidationException::withMessages([
                'email' => 'У кабинета нет e-mail — подтверждать нечего.',
            ]);
        }

        if ($user->email_verified_at) {
            return ['message' => 'Адрес уже подтверждён.'];
        }

        $user->forceFill(['email_verified_at' => now()])->save();

        CustomerAdminAction::record(
            $employee,
            CustomerAdminAction::ACTION_VERIFY_EMAIL,
            $user,
            details: ['email' => $user->email],
            ip: $ip,
        );

        return ['message' => 'Адрес '.$user->email.' отмечен подтверждённым.'];
    }

    /** Завершить все сессии кабинета (вход с чужого или потерянного устройства). */
    public function logoutAll(User $user, AisEmployee $employee, ?string $ip = null): array
    {
        $killed = $user->tokens()->delete();

        CustomerAdminAction::record(
            $employee,
            CustomerAdminAction::ACTION_LOGOUT_ALL,
            $user,
            details: ['sessions_closed' => $killed],
            ip: $ip,
        );

        return [
            'message' => $killed
                ? 'Завершено сессий: '.$killed.'. На всех устройствах потребуется войти заново.'
                : 'Активных сессий не было.',
            'sessions_closed' => $killed,
        ];
    }

    /**
     * Отвязать VK. Кабинет без пароля после этого войти НЕ сможет —
     * предупреждаем сразу, а не после.
     */
    public function unlinkVk(User $user, AisEmployee $employee, ?string $ip = null): array
    {
        $identity = $user->identities()->where('provider', UserIdentity::PROVIDER_VK)->first();

        if (! $identity) {
            throw ValidationException::withMessages([
                'vk' => 'У этого кабинета нет входа через VK.',
            ]);
        }

        $vkId = $identity->provider_user_id;
        $identity->delete();

        CustomerAdminAction::record(
            $employee,
            CustomerAdminAction::ACTION_UNLINK_VK,
            $user,
            details: ['vk_id' => $vkId],
            ip: $ip,
        );

        return [
            'message' => $user->hasPassword()
                ? 'Вход через VK отвязан. Остаётся вход по e-mail и паролю.'
                : 'Вход через VK отвязан. У кабинета НЕТ пароля — покупателю нужно задать его '
                    .'через «Забыли пароль», иначе войти будет нечем.',
        ];
    }

    /**
     * Смена e-mail кабинета.
     *
     * Адрес здесь — не просто контакт: кабинет видит покупки, сделанные на
     * него до регистрации (User::orders), поэтому смена меняет и СОСТАВ
     * видимых заказов. Поэтому: адрес из чёрного списка не принимаем,
     * занятый другим кабинетом — тоже, и в ответе честно пишем, сколько
     * заказов станет видно.
     */
    public function changeEmail(User $user, string $email, AisEmployee $employee, ?string $ip = null): array
    {
        $email = mb_strtolower(trim($email));
        $old = $user->email;

        if ($email === '') {
            throw ValidationException::withMessages(['email' => 'Укажите адрес.']);
        }

        if ($email === $this->fallbackEmail()) {
            throw ValidationException::withMessages([
                'email' => 'Это служебный адрес для фискальных чеков — кабинету его присваивать нельзя.',
            ]);
        }

        if (\App\Models\BlockedEmail::blocked($email)) {
            throw ValidationException::withMessages([
                'email' => 'Этот адрес в чёрном списке.',
            ]);
        }

        if (User::whereRaw('lower(email) = ?', [$email])->where('id', '!=', $user->id)->exists()) {
            throw ValidationException::withMessages([
                'email' => 'Этот адрес уже занят другим кабинетом.',
            ]);
        }

        if ($email === mb_strtolower((string) $old)) {
            return ['message' => 'Адрес не изменился.'];
        }

        // Сколько «гостевых» покупок станет видно в кабинете после смены.
        $gained = (int) Order::whereNull('user_id')->whereRaw('lower(email) = ?', [$email])->count();

        $user->forceFill([
            'email' => $email,
            // Адрес назначил сотрудник — значит он с покупателем говорил;
            // требовать от покупателя код после этого незачем.
            'email_verified_at' => now(),
        ])->save();

        // Логин сменился — строку способа входа паролем приводим в
        // соответствие, иначе по старому адресу остался бы «логин»,
        // которого в users больше нет.
        $user->identities()
            ->where('provider', UserIdentity::PROVIDER_PASSWORD)
            ->update(['provider_user_id' => $email]);

        CustomerAdminAction::record(
            $employee,
            CustomerAdminAction::ACTION_CHANGE_EMAIL,
            $user,
            details: ['from' => $old, 'to' => $email, 'orders_gained' => $gained],
            ip: $ip,
        );

        return [
            'message' => 'Адрес изменён: '.($old ?: '—').' → '.$email.'.'
                .($gained ? ' В кабинете стало видно покупок на этот адрес: '.$gained.'.' : ''),
        ];
    }

    /* ─────────────── Вход в кабинет от имени покупателя ─────────────── */

    /**
     * Выдать одноразовый код входа в кабинет.
     *
     * Сам токен кабинета в адресную строку не отдаём: ссылку сотрудник
     * откроет в браузере, и токен осел бы в истории и в журналах nginx —
     * ровно по этой причине в АИС запрещён вход по `?token=` из интернета.
     * Код живёт две минуты, обменивается ОДИН раз, и обменивает его сервер
     * Nuxt своим запросом (см. frontend/server/routes/auth/impersonate).
     */
    public function issueImpersonationCode(User $user, AisEmployee $employee, ?string $ip = null): array
    {
        $code = Str::random(48);

        Cache::put($this->impersonationKey($code), [
            'user_id' => $user->id,
            'employee_id' => $employee->id,
            'employee_login' => $employee->login,
            'employee_name' => $employee->fullName,
            'ip' => $ip,
        ], self::IMPERSONATE_CODE_TTL);

        CustomerAdminAction::record(
            $employee,
            CustomerAdminAction::ACTION_IMPERSONATE,
            $user,
            ip: $ip,
        );

        return [
            'url' => rtrim(config('site.url'), '/').'/auth/impersonate?code='.$code,
            'expires_in' => self::IMPERSONATE_CODE_TTL,
        ];
    }

    /**
     * Обменять код на токен кабинета. Токен помечен способностью
     * `impersonated` — по ней сервер запрещает из такой сессии покупать,
     * возвращать и менять данные, а срок её жизни короче обычной
     * (config site.impersonate_ttl_minutes).
     *
     * @return array{token: string, user: User, employee: string}
     */
    public function consumeImpersonationCode(string $code): array
    {
        $data = Cache::pull($this->impersonationKey($code));

        if (! $data) {
            throw ValidationException::withMessages([
                'code' => 'Ссылка входа устарела. Выдайте её в АИС заново.',
            ]);
        }

        $user = User::find($data['user_id']);

        if (! $user) {
            throw ValidationException::withMessages(['code' => 'Кабинет не найден.']);
        }

        $token = $user->createToken(
            'impersonate:'.$data['employee_login'],
            [\App\Support\ImpersonationToken::ABILITY],
        )->plainTextToken;

        return [
            'token' => $token,
            'user' => $user,
            'employee' => $data['employee_name'] ?: $data['employee_login'],
        ];
    }

    private function impersonationKey(string $code): string
    {
        return 'customer-impersonate:'.hash('sha256', $code);
    }

    /* ────────────────────────── Мелочи ────────────────────────── */

    /** Строка «?, ?, ?» под список значений — массивы Postgres здесь опасны. */
    private function placeholders(array $values): string
    {
        return implode(', ', array_fill(0, count($values), '?'));
    }

    private function fallbackEmail(): string
    {
        return mb_strtolower((string) config('site.fiscal_fallback_email'));
    }

    /** Даты в АИС — в одном виде: d.m.Y H:i московского времени. */
    private function date($value): string
    {
        return \Illuminate\Support\Carbon::parse($value)
            ->timezone(config('app.timezone'))
            ->format('d.m.Y H:i');
    }
}
