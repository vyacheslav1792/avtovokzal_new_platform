<?php

namespace App\Console\Commands;

use App\Services\Legacy\LegacyOrderMapper;
use App\Services\Legacy\LegacySiteConnection;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use PDO;
use Throwable;

/**
 * Перенос истории покупок со старого сайта (Firebird site.fdb) в новую базу:
 * ORDERS → orders + tickets, BACKS → refunds.
 *
 * Пользователей (site.USERS) и список рассылки (site.EMAIL) НЕ переносим —
 * так решено при переносе: кабинет на новой платформе заводится заново, а
 * покупки старого покупателя находятся по orders.email.
 *
 * Заказы переносятся со своим legacy-идентификатором (orders.id — varchar(36)),
 * поэтому повторный запуск не плодит дубли: строки вставляются с ON CONFLICT
 * DO NOTHING. Чистый прогон — с ключом --fresh.
 */
class ImportLegacySite extends Command
{
    protected $signature = 'legacy:import-site
        {--fresh : очистить orders/tickets/refunds перед импортом}
        {--only= : orders|refunds — перенести только часть}
        {--since= : брать заказы с этой даты (YYYY-MM-DD)}
        {--limit= : ограничить число заказов (для проверки)}
        {--chunk=500 : размер пачки вставки}';

    protected $description = 'Перенести заказы, билеты и возвраты из старой базы сайта (Firebird)';

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

        $counters = ['orders' => 0, 'tickets' => 0, 'refunds' => 0, 'skipped' => 0];

        if ($only !== 'refunds') {
            $this->importOrders($legacy, $mapper, $chunk, $counters);
        }

        if ($only !== 'orders') {
            $this->importRefunds($legacy, $mapper, $chunk, $counters);
        }

        $this->newLine();
        $this->table(['Заказы', 'Билеты', 'Возвраты', 'Пропущено', 'Время'], [[
            number_format($counters['orders'], 0, '.', ' '),
            number_format($counters['tickets'], 0, '.', ' '),
            number_format($counters['refunds'], 0, '.', ' '),
            $counters['skipped'],
            gmdate('H:i:s', (int) (microtime(true) - $started)),
        ]]);

        return self::SUCCESS;
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
