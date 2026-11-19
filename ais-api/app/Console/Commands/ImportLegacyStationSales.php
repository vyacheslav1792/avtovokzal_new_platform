<?php

namespace App\Console\Commands;

use App\Services\StationSalesSource;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Throwable;

/** Разовый перенос всего SALE_ALL; курсор переживает обрыв и повторный запуск. */
class ImportLegacyStationSales extends Command
{
    protected $signature = 'station-sales:import-legacy {--pages= : ограничить число порций за запуск}
        {--restart : перечитать с начала, уже имеющиеся строки не перезаписываются}';

    protected $description = 'Перенести архив SALE_ALL из Firebird в Postgres порциями, без изменения актуальности станций';

    public function handle(): int
    {
        $sourceId = substr(hash('sha256', json_encode(array_intersect_key(config('station_sales.legacy'), array_flip(['db_host', 'db_port', 'db_name'])))), 0, 16);
        $path = storage_path('app/station-sales-import-'.$sourceId.'.json');
        $lock = fopen(storage_path('app/station-sales-import.lock'), 'c+');
        if (! $lock || ! flock($lock, LOCK_EX | LOCK_NB)) {
            $this->error('Перенос SALE_ALL уже выполняется.');

            return self::FAILURE;
        }
        try {
            $state = ! $this->option('restart') && is_file($path)
                ? json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR)
                : ['cursor' => null, 'read' => 0, 'inserted' => 0, 'complete' => false];
            $limit = $this->option('pages') === null ? PHP_INT_MAX : max(1, (int) $this->option('pages'));
            for ($page = 0; ! $state['complete'] && $page < $limit; $page++) {
                $process = new Process([
                    PHP_BINARY, '-d', 'memory_limit=128M', '-d', 'display_errors=stderr', base_path('artisan'),
                    'station-sales:read-page', '--legacy', '--cursor='.json_encode($state['cursor'], JSON_THROW_ON_ERROR), '--no-ansi',
                ], base_path());
                $process->setTimeout((int) config('station_sales.page_timeout'));
                $process->mustRun();
                $rows = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
                $records = array_map(fn ($row) => ['report_hash' => null] + StationSalesSource::record($row, (int) $row['AWTCOD'], now()->toDateTimeString()), $rows);
                // Сбор с живых станций мог уже принести более свежие версии:
                // старый архив их не затирает. Актуальность здесь НЕ выставляется.
                if ($records) {
                    $state['inserted'] += DB::table('station_sales')->insertOrIgnore($records);
                    $last = end($rows);
                    $state['cursor'] = array_intersect_key($last, array_flip(StationSalesSource::KEY));
                }
                $state['read'] += count($rows);
                $state['complete'] = count($rows) < (int) config('station_sales.legacy_page_size');
                if (file_put_contents($path.'.tmp', json_encode($state, JSON_THROW_ON_ERROR), LOCK_EX) === false
                    || ! rename($path.'.tmp', $path)) {
                    throw new \RuntimeException('Не удалось сохранить курсор переноса. Повторный запуск безопасен.');
                }
                if ($page % 20 === 0 || $state['complete']) {
                    $this->line('Прочитано: '.$state['read'].'; добавлено: '.$state['inserted']);
                }
            }
            $this->info($state['complete'] ? 'Архив перенесён.' : 'Курсор сохранён. Следующий запуск продолжит перенос.');

            return self::SUCCESS;
        } catch (Throwable $e) {
            report($e);
            $this->error('Перенос остановлен; сохранённые порции остаются в БД. Повторите команду для продолжения. '.$e->getMessage());

            return self::FAILURE;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}
