<?php

namespace App\Console\Commands;

use App\Services\StationSalesSource;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

class ReadStationSalesPage extends Command
{
    protected $signature = 'station-sales:read-page {run? : внутреннее задание сбора}
        {--period : читать весь период по общему курсору} {--legacy : читать прежний SALE_ALL} {--cursor= : JSON полного ключа SALE_ALL}';

    protected $description = 'Прочитать одну порцию SALE (внутренняя команда сборщика)';

    protected $hidden = true;

    public function handle(StationSalesSource $source): int
    {
        try {
            if ($this->option('legacy')) {
                $cursor = $this->option('cursor') ? json_decode($this->option('cursor'), true, flags: JSON_THROW_ON_ERROR) : null;
                $this->output->write(json_encode($source->legacyPage($cursor), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));

                return self::SUCCESS;
            }
            $run = DB::table('station_sales_sync_runs')->find($this->argument('run'));
            if (! $run || $run->status !== 'running') {
                throw new \RuntimeException('Задание не выполняется.');
            }
            $cursor = $run->cursor ? json_decode($run->cursor, true, flags: JSON_THROW_ON_ERROR) : null;
            // Старый воркер при выкладке может ещё заканчивать свою суточную страницу.
            if ($this->option('period')) {
                $from = $run->date_from;
                $to = $run->date_to;
                $extra = StationSalesSource::extraDays($run);
                $cursor = $cursor['after'] ?? null;
            } else {
                $from = $to = $run->next_day;
                $extra = [];
            }
            $page = $source->page($run->station_code, $from, $to, $extra, $cursor, $run->page_size, $run->mode);
            $this->output->write(json_encode($page, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->getOutput()->getErrorStyle()->writeln($e->getMessage());

            return self::FAILURE;
        }
    }
}
