<?php

namespace App\Console\Commands;

use App\Services\StationSalesSync;
use Illuminate\Console\Command;
use Throwable;

class WorkStationSales extends Command
{
    protected $signature = 'station-sales:work {--seconds=50 : бюджет времени одного прохода}';

    protected $description = 'Обработать небольшие порции очереди продаж станций (ofelia раз в минуту)';

    public function handle(StationSalesSync $sync): int
    {
        $deadline = microtime(true) + max(1, min(50, (int) $this->option('seconds')));
        // Выбираем станции по очереди: сбой одной не останавливает остальные.
        do {
            $run = $sync->claim();
            if (! $run) {
                break;
            }
            try {
                $rows = $sync->readChunk($run);
            } catch (Throwable $e) {
                $sync->retryRead($run, $e);

                continue;
            }
            try {
                $sync->acceptChunk($run, $rows);
            } catch (Throwable $e) {
                $sync->fail($run, $e);
            }
        } while (microtime(true) + (int) config('station_sales.page_timeout') < $deadline);

        return self::SUCCESS;
    }
}
