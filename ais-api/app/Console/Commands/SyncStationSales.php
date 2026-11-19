<?php

namespace App\Console\Commands;

use App\Services\SiteSettings;
use App\Services\StationSalesSync;
use Illuminate\Console\Command;

class SyncStationSales extends Command
{
    protected $signature = 'station-sales:sync {--scheduled : выполнить только в настроенное время} {--stations= : коды станций через запятую, иначе все}
        {--mode= : incremental или full} {--from= : первая дата продаж} {--to= : последняя дата продаж}';

    protected $description = 'Поставить в очередь сбор продаж станций для сводного отчёта';

    public function handle(StationSalesSync $sync, SiteSettings $settings): int
    {
        $scheduledOn = null;
        if ($this->option('scheduled')) {
            $now = now(config('app.timezone'));
            if ($now->format('H:i') !== $settings->stationSalesAutoTime()) {
                return self::SUCCESS;
            }
            $scheduledOn = $now->toDateString();
        }
        $codes = $this->option('stations') ? explode(',', $this->option('stations')) : null;
        $result = $sync->start($codes, $this->option('from'), $this->option('to'), $scheduledOn, $this->option('mode'));
        $this->info('Поставлено станций: '.count($result['run_ids']).'; уже обновляются: '.count($result['busy_stations']).'. Период '.$result['date1'].' — '.$result['date2']);

        return self::SUCCESS;
    }
}
