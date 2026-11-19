<?php

namespace App\Console\Commands;

use App\Services\StationSalesSource;
use App\Services\StationSalesSync;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

class ReadStationSalesPage extends Command
{
    protected $signature = 'station-sales:read-page {run? : внутреннее задание сбора}
        {--output= : файл строк JSON для порции} {--lease= : токен захвата задания} {--legacy : читать прежний SALE_ALL} {--cursor= : JSON полного ключа SALE_ALL}';

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
            if (! $run || $run->status !== 'running' || ! $this->option('lease')
                || $run->lease_token !== $this->option('lease')) {
                throw new \RuntimeException('Задание не выполняется.');
            }
            $output = $this->option('output');
            $stream = is_string($output) && is_file($output) ? fopen($output, 'wb') : false;
            if ($stream === false) {
                throw new \RuntimeException('Не удалось открыть файл порции продаж.');
            }
            try {
                foreach ($source->rows($run->station_code, StationSalesSync::chunk($run)) as $row) {
                    $line = json_encode($row, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)."\n";
                    if (fwrite($stream, $line) !== strlen($line)) {
                        throw new \RuntimeException('Не удалось сохранить порцию продаж во временный файл.');
                    }
                }
                if (! fflush($stream)) {
                    throw new \RuntimeException('Не удалось сохранить порцию продаж во временный файл.');
                }
            } finally {
                fclose($stream);
            }

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->getOutput()->getErrorStyle()->writeln($e->getMessage());

            return self::FAILURE;
        }
    }
}
