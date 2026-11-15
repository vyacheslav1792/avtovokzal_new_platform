<?php

namespace App\Console\Commands;

use App\Models\UserIdentity;
use App\Services\Legacy\LegacyOrderMapper;
use App\Services\Legacy\LegacySiteConnection;
use App\Support\Phone;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use PDO;
use RuntimeException;
use Throwable;

/**
 * Перенос данных со старого сайта (Firebird site.fdb) в новую базу:
 * ORDERS → orders + tickets, BACKS → refunds, USERS → users.
 *
 * Список рассылки (site.EMAIL) НЕ переносим — так решено при переносе.
 *
 * Кабинеты переносятся **только те, за которыми есть покупки** (решение
 * 15.11.2026): из 99 тыс. записей legacy заказы есть у 84 тыс., остальные —
 * пустые кабинеты, которые ничего не дают. Пароль переезжает в виде
 * legacy-хэша md5(соль + пароль) и при первом же входе заменяется на
 * bcrypt (AuthService::login); открытый `USERS.PASSWORD_RAW` в новую базу
 * не попадает вовсе.
 *
 * Заказы переносятся со своим legacy-идентификатором (orders.id — varchar(36)),
 * поэтому повторный запуск не плодит дубли: строки вставляются с ON CONFLICT
 * DO NOTHING. Чистый прогон — с ключом --fresh.
 */
class ImportLegacySite extends Command
{
    protected $signature = 'legacy:import-site
        {--fresh : очистить orders/tickets/refunds перед импортом}
        {--only= : orders|refunds|users — перенести только часть}
        {--since= : брать заказы с этой даты (YYYY-MM-DD)}
        {--limit= : ограничить число заказов (для проверки)}
        {--chunk=500 : размер пачки вставки}';

    protected $description = 'Перенести заказы, билеты, возвраты и кабинеты из старой базы сайта (Firebird)';

    public function handle(LegacyOrderMapper $mapper): int
    {
        $legacy = new LegacySiteConnection;
        $chunk = max(1, (int) $this->option('chunk'));
        $only = $this->option('only');
        $started = microtime(true);

        if ($this->option('fresh')) {
            $this->warn('Очищаю orders, tickets, refunds…');
            DB::statement('truncate table refunds, tickets, orders restart identity cascade');
        }

        $counters = ['orders' => 0, 'tickets' => 0, 'refunds' => 0, 'users' => 0, 'linked' => 0, 'skipped' => 0];

        if ($only === null || $only === 'orders') {
            $this->importOrders($legacy, $mapper, $chunk, $counters);
        }

        if ($only === null || $only === 'refunds') {
            $this->importRefunds($legacy, $mapper, $chunk, $counters);
        }

        if ($only === null || $only === 'users') {
            $this->importUsers($legacy, $chunk, $counters);
        }

        $this->newLine();
        $this->table(['Заказы', 'Билеты', 'Возвраты', 'Кабинеты', 'Заказов связано', 'Пропущено', 'Время'], [[
            number_format($counters['orders'], 0, '.', ' '),
            number_format($counters['tickets'], 0, '.', ' '),
            number_format($counters['refunds'], 0, '.', ' '),
            number_format($counters['users'], 0, '.', ' '),
            number_format($counters['linked'], 0, '.', ' '),
            $counters['skipped'],
            gmdate('H:i:s', (int) (microtime(true) - $started)),
        ]]);

        return self::SUCCESS;
    }

    /**
     * Кабинеты: USERS → users. Переносим только адреса, за которыми есть
     * заказы, — остальные кабинеты пусты.
     *
     * Пароль кладём в `legacy_password` (md5 старого сайта); `password`
     * остаётся пустым, пока покупатель не войдёт — тогда он сам
     * пересохранится в bcrypt. Адрес НЕ помечаем подтверждённым: в legacy
     * такого признака не было, и подтверждение придёт первым входом или
     * кодом из письма.
     */
    private function importUsers(LegacySiteConnection $legacy, int $chunk, array &$counters): void
    {
        if ((string) config('legacy_site.password_salt') === '') {
            $this->error('Не задан LEGACY_SITE_PASSWORD_SALT — без соли перенос паролей бессмыслен.');
            $this->line('Соль лежит в legacy `inc/config.php` ($CONFIG[\'password_sol\']).');

            return;
        }

        $this->info('Кабинеты: собираю адреса, за которыми есть заказы…');
        // 84 тыс. адресов — держать их в памяти дешевле, чем спрашивать
        // базу про каждую из 99 тыс. строк legacy.
        $withOrders = DB::table('orders')
            ->selectRaw('lower(email) as email')
            ->distinct()
            ->pluck('email')
            ->flip();

        $total = (int) $legacy->scalar('select count(*) from USERS');
        $this->info("Кабинеты legacy: {$total}, из них с заказами: {$withOrders->count()}");
        $bar = $this->output->createProgressBar($total);

        // DATE_REG desc: один адрес в legacy встречается дважды в разном
        // регистре, а в новой схеме e-mail уникален — оставляем свежую
        // запись (вставка идёт insertOrIgnore).
        $cursor = $legacy->cursor('select * from USERS order by DATE_REG desc');
        $batch = [];
        $seen = 0;

        while ($row = $cursor->fetch(PDO::FETCH_ASSOC)) {
            $seen++;
            $user = $this->mapUser($row, $withOrders, $counters);

            if ($user !== null) {
                $batch[] = $user;
            }

            if (count($batch) >= $chunk) {
                $counters['users'] += $this->flushUsers($batch);
            }

            if ($seen % 500 === 0) {
                $bar->advance(500);
            }
        }

        $counters['users'] += $this->flushUsers($batch);
        $bar->finish();
        $this->newLine();

        $this->info('Связываю заказы с кабинетами (orders.user_id)…');
        $counters['linked'] += $this->linkOrders();
    }

    /**
     * Строка USERS → строка users. null — запись не переносим.
     *
     * @param  \Illuminate\Support\Collection<string, int>  $withOrders
     */
    private function mapUser(array $row, $withOrders, array &$counters): ?array
    {
        $email = mb_strtolower(trim((string) ($row['EMAIL'] ?? '')));

        if ($email === '' || ! $withOrders->has($email)) {
            return null;
        }

        /*
         * Адрес без «@» (в legacy таких 166 — опечатки при регистрации)
         * логином быть не может: форма входа его не примет, код из письма
         * отправить некуда. Такой кабинет не переносим, но в лог пишем.
         */
        if (! str_contains($email, '@')) {
            $counters['skipped']++;
            $this->logSkipped('user', $email, new RuntimeException('адрес без @ — логином быть не может'));

            return null;
        }

        $md5 = mb_strtolower(trim((string) ($row['PASSWORD'] ?? '')));
        $registered = $this->legacyDateTime($row['DATE_REG'] ?? null) ?? now()->format('Y-m-d H:i:s');

        return [
            'email' => mb_substr($email, 0, 255),
            'name' => null,
            // В legacy телефон заполнялся всего у 19 кабинетов и в
            // произвольном виде; непонятный номер лучше не тащить.
            'phone' => Phone::normalize($row['PHONE'] ?? null),
            'password' => null,
            'legacy_password' => preg_match('/^[0-9a-f]{32}$/', $md5) === 1 ? $md5 : null,
            'email_verified_at' => null,
            'created_at' => $registered,
            'updated_at' => $registered,
        ];
    }

    /** @param  array<int, array>  $batch */
    private function flushUsers(array &$batch): int
    {
        if ($batch === []) {
            return 0;
        }

        $inserted = DB::transaction(function () use ($batch) {
            $count = DB::table('users')->insertOrIgnore($batch);

            // Способ входа 'password': у перенесённых кабинетов пароль есть,
            // пусть пока и в legacy-хэше.
            $emails = array_column($batch, 'email');
            $identities = DB::table('users')
                ->whereIn('email', $emails)
                ->pluck('id', 'email')
                ->map(fn (int $id, string $email) => [
                    'user_id' => $id,
                    'provider' => UserIdentity::PROVIDER_PASSWORD,
                    'provider_user_id' => $email,
                    'created_at' => now(),
                    'updated_at' => now(),
                ])
                ->values()
                ->all();

            if ($identities !== []) {
                DB::table('user_identities')->insertOrIgnore($identities);
            }

            return $count;
        });

        $batch = [];

        return $inserted;
    }

    /**
     * Заказы, купленные на адрес кабинета, закрепляем за ним — так же, как
     * это делает claimOrders при обычной регистрации. Пачками, иначе это
     * один UPDATE на миллион строк.
     */
    private function linkOrders(): int
    {
        $max = (int) DB::table('users')->max('id');
        $step = 2000;
        $linked = 0;

        // Одним UPDATE по всей таблице это миллион строк в одной
        // транзакции; пачками по диапазону id кабинетов и блокировки
        // короче, и прогресс виден.
        for ($from = 0; $from <= $max; $from += $step) {
            $to = $from + $step;

            $linked += DB::affectingStatement(
                'update orders o set user_id = u.id
                   from users u
                  where u.id > ? and u.id <= ?
                    and u.email is not null
                    and o.email = u.email
                    and o.user_id is null',
                [$from, $to],
            );
        }

        return $linked;
    }

    /** TIMESTAMP legacy → 'Y-m-d H:i:s' (пояс приложения — Europe/Moscow). */
    private function legacyDateTime(?string $value): ?string
    {
        $value = trim((string) $value);

        if ($value === '' || ! preg_match('/^(\d{4}-\d{2}-\d{2})[ T](\d{2}:\d{2}:\d{2})/', $value, $m)) {
            return null;
        }

        return $m[1].' '.$m[2];
    }

    private function importOrders(
        LegacySiteConnection $legacy,
        LegacyOrderMapper $mapper,
        int $chunk,
        array &$counters,
    ): void {
        [$where, $bindings] = $this->filter();
        $limit = (int) $this->option('limit');
        $first = $limit > 0 ? "first {$limit} " : '';

        $total = $limit > 0
            ? $limit
            : (int) $legacy->scalar("select count(*) from ORDERS {$where}", $bindings);

        $this->info("Заказы: {$total}");
        $bar = $this->output->createProgressBar($total);

        $cursor = $legacy->cursor("select {$first}* from ORDERS {$where}", $bindings);

        $orders = [];
        $tickets = [];

        while ($row = $cursor->fetch(PDO::FETCH_ASSOC)) {
            try {
                ['order' => $order, 'tickets' => $rows] = $mapper->order($row);
                $orders[] = $order;
                $tickets = array_merge($tickets, $rows);
            } catch (Throwable $e) {
                $counters['skipped']++;
                $this->logSkipped('order', $row['ORDER_ID'] ?? '?', $e);
            }

            if (count($orders) >= $chunk) {
                $this->flushOrders($orders, $tickets, $counters);
                $bar->advance($chunk);
            }
        }

        $rest = count($orders);
        $this->flushOrders($orders, $tickets, $counters);
        $bar->advance($rest);
        $bar->finish();
        $this->newLine();
    }

    private function importRefunds(
        LegacySiteConnection $legacy,
        LegacyOrderMapper $mapper,
        int $chunk,
        array &$counters,
    ): void {
        $total = (int) $legacy->scalar('select count(*) from BACKS');
        $this->info("Возвраты: {$total}");
        $bar = $this->output->createProgressBar($total);

        $cursor = $legacy->cursor('select * from BACKS');
        $batch = [];

        while ($row = $cursor->fetch(PDO::FETCH_ASSOC)) {
            $orderId = trim((string) ($row['ORDER_ID'] ?? ''));

            try {
                $batch[] = $mapper->refund($row, $orderId);
            } catch (Throwable $e) {
                $counters['skipped']++;
                $this->logSkipped('refund', $orderId, $e);
            }

            if (count($batch) >= $chunk) {
                $counters['refunds'] += $this->flushRefunds($batch);
                $bar->advance($chunk);
            }
        }

        $rest = count($batch);
        $counters['refunds'] += $this->flushRefunds($batch);
        $bar->advance($rest);
        $bar->finish();
        $this->newLine();
    }

    /** @param  array<int, array>  $orders */
    private function flushOrders(array &$orders, array &$tickets, array &$counters): void
    {
        if ($orders === []) {
            $tickets = [];

            return;
        }

        DB::transaction(function () use (&$orders, &$tickets, &$counters) {
            $counters['orders'] += DB::table('orders')->insertOrIgnore($orders);

            foreach (array_chunk($tickets, 1000) as $part) {
                $counters['tickets'] += DB::table('tickets')->insertOrIgnore($part);
            }
        });

        $orders = [];
        $tickets = [];
    }

    /**
     * Возвраты вставляем только для заказов, которые есть в новой базе:
     * при --limit/--since часть заказов не переносится, а FK этого не простит.
     *
     * @param  array<int, array>  $batch
     */
    private function flushRefunds(array &$batch): int
    {
        if ($batch === []) {
            return 0;
        }

        $ids = array_unique(array_column($batch, 'order_id'));
        $known = DB::table('orders')->whereIn('id', $ids)->pluck('id')->flip();
        $rows = array_values(array_filter($batch, fn (array $r) => $known->has($r['order_id'])));

        $inserted = 0;
        if ($rows !== []) {
            $inserted = DB::table('refunds')->insertOrIgnore($rows);
        }

        $batch = [];

        return $inserted;
    }

    /** @return array{0: string, 1: array} */
    private function filter(): array
    {
        $since = $this->option('since');

        return $since ? ['where DATE_ORDER >= ?', [$since]] : ['', []];
    }

    private function logSkipped(string $kind, string $id, Throwable $e): void
    {
        file_put_contents(
            storage_path('logs/legacy-import-skipped.log'),
            sprintf("%s\t%s\t%s\t%s\n", date('c'), $kind, $id, $e->getMessage()),
            FILE_APPEND,
        );
    }
}
