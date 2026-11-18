<?php

namespace App\Console\Commands;

use App\Services\StationSalesSync;
use Illuminate\Console\Command;

class SyncStationSales extends Command
{
    protected $signature = 'station-sales:sync {--stations= : коды станций через запятую, иначе все}
        {--from= : первая дата продаж} {--to= : последняя дата продаж}';

    protected $description = 'Поставить в очередь сбор продаж станций для сводного отчёта';

    public function handle(StationSalesSync $sync): int
    {
        $codes = $this->option('stations') ? explode(',', $this->option('stations')) : null;
        $result = $sync->start($codes, $this->option('from'), $this->option('to'));
        $this->info('Поставлено станций: '.count($result['run_ids']).'; уже обновляются: '.count($result['busy_stations']).'. Период '.$result['date1'].' — '.$result['date2']);

        return self::SUCCESS;
    }
}
