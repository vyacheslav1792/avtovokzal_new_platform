<?php

namespace App\Console\Commands;

use App\Services\ScheduleService;
use Illuminate\Console\Command;

/**
 * Обновление регулярного расписания станций (замена legacy
 * `getrasp.php?action=raspisanie`, который писал HTML-файлы вручную
 * по кнопке в служебной странице).
 */
class SyncSchedules extends Command
{
    protected $signature = 'schedule:sync {--awtcod= : Обновить только одну станцию}';

    protected $description = 'Обновить регулярное расписание станций из API продаж';

    public function handle(ScheduleService $schedules): int
    {
        $rows = $this->option('awtcod')
            ? [$schedules->sync((int) $this->option('awtcod'), (string) $this->option('awtcod'))]
            : $schedules->syncAll();

        $this->table(
            ['Место', 'Маршрутов', 'Ошибка'],
            array_map(fn (array $r) => [
                // У пункта посадки кода станции нет: расписание собрано из
                // баз тех станций, что ведут проходящие рейсы.
                $r['name'].($r['awtcod'] === null ? ' (остановка)' : ' ('.$r['awtcod'].')'),
                $r['routes'] ?? ($r['skipped'] ?? '—'),
                $r['error'] ? mb_substr($r['error'], 0, 60) : '',
            ], $rows),
        );

        $failed = count(array_filter($rows, fn (array $r) => $r['error'] !== null));

        return $failed === count($rows) && $rows !== [] ? self::FAILURE : self::SUCCESS;
    }
}
