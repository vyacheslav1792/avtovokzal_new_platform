<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;
use Throwable;

/** Возобновляемая очередь в БД; HTTP только ставит задания и читает состояние. */
class StationSalesSync
{
    public function __construct(private readonly StationRegistry $stations, private readonly SiteSettings $settings) {}

    public function currentPeriod(): array
    {
        $today = CarbonImmutable::today('Europe/Moscow');
        $days = $this->settings->stationSalesLookbackDays();

        return ['date1' => $today->subDays($days)->toDateString(), 'date2' => $today->toDateString(), 'lookback_days' => $days];
    }

    public function start(?array $codes = null, ?string $date1 = null, ?string $date2 = null): array
    {
        $all = $this->stations->all();
        $codes ??= array_keys($all);
        $codes = array_values(array_unique(array_map('intval', $codes)));
        if ($codes === [] || array_diff($codes, array_keys($all))) {
            throw ValidationException::withMessages(['stations' => 'Выберите хотя бы одну действующую станцию.']);
        }
        if (($date1 === null) !== ($date2 === null)) {
            throw ValidationException::withMessages(['date1' => 'Для своего периода укажите обе даты.']);
        }
        $current = $this->currentPeriod();
        $date1 ??= $current['date1'];
        $date2 ??= $current['date2'];
        validator(['date1' => $date1, 'date2' => $date2], [
            'date1' => ['required', 'date_format:Y-m-d', 'before_or_equal:date2', 'after_or_equal:1900-01-01'],
            'date2' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.$current['date2']],
        ])->validate();

        $created = $busy = [];
        foreach ($codes as $code) {
            $id = DB::transaction(function () use ($code, $date1, $date2, $current) {
                DB::table('station_sales_sync_states')->insertOrIgnore(['station_code' => $code]);
                // Один незавершённый сбор на станцию, включая параллельные HTTP/cron.
                DB::table('station_sales_sync_states')->where('station_code', $code)->lockForUpdate()->first();
                if (DB::table('station_sales_sync_runs')->where('station_code', $code)
                    ->whereIn('status', ['queued', 'running'])->exists()) {
                    return null;
                }

                return DB::table('station_sales_sync_runs')->insertGetId([
                    'station_code' => $code, 'status' => 'queued',
                    'is_current' => $date1 <= $current['date1'] && $date2 === $current['date2'],
                    'date_from' => $date1, 'date_to' => $date2, 'next_day' => $date2,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            });
            if ($id === null) {
                $busy[] = $code;
            } else {
                $created[] = $id;
            }
        }

        return ['run_ids' => $created, 'busy_stations' => $busy, 'date1' => $date1, 'date2' => $date2];
    }

    public function status(): array
    {
        $states = DB::table('station_sales_sync_states')->get()->keyBy('station_code');
        $latestIds = DB::table('station_sales_sync_runs')->selectRaw('MAX(id) AS id')->groupBy('station_code');
        $runs = DB::table('station_sales_sync_runs')->whereIn('id', $latestIds)->get()->keyBy('station_code');
        $lastSuccessIds = DB::table('station_sales_sync_runs')->selectRaw('MAX(id) AS id')
            ->where('status', 'completed')->groupBy('station_code');
        $lastSuccess = DB::table('station_sales_sync_runs')->whereIn('id', $lastSuccessIds)->get()->keyBy('station_code');
        $result = [];
        foreach ($this->stations->all() as $code => $station) {
            $run = $runs[$code] ?? null;
            $success = $lastSuccess[$code] ?? null;
            $state = $states[$code] ?? null;
            $days = $run ? CarbonImmutable::parse($run->date_from)->diffInDays(CarbonImmutable::parse($run->date_to)) + 1 : 0;
            $result[] = [
                'code' => (int) $code, 'name' => $station['name'],
                'current_updated_at' => $state?->current_updated_at,
                'current_from' => $state?->current_from, 'current_to' => $state?->current_to,
                'current_covers_window' => $state?->current_from !== null
                    && $state->current_from <= $this->currentPeriod()['date1']
                    && $state->current_to >= $this->currentPeriod()['date2'],
                'last_success' => $success ? [
                    'date1' => $success->date_from, 'date2' => $success->date_to, 'finished_at' => $success->finished_at,
                ] : null,
                'run' => $run ? [
                    'id' => $run->id, 'status' => $run->status, 'is_current' => (bool) $run->is_current,
                    'date1' => $run->date_from, 'date2' => $run->date_to,
                    'day' => $run->next_day, 'days_done' => $run->days_done, 'days_total' => $days,
                    'rows_read' => $run->rows_read, 'error' => $run->error,
                    'created_at' => $run->created_at, 'updated_at' => $run->updated_at, 'finished_at' => $run->finished_at,
                ] : null,
            ];
        }

        return ['period' => $this->currentPeriod(), 'stations' => $result];
    }

    /** Claim с lease: после перезапуска сервера продолжаем с сохранённого курсора. */
    public function claim(): ?object
    {
        return DB::transaction(function () {
            $run = DB::table('station_sales_sync_runs')->whereIn('status', ['queued', 'running'])
                ->where(fn ($q) => $q->whereNull('lease_until')->orWhere('lease_until', '<', now()))
                ->orderBy('updated_at')->orderBy('id')->lockForUpdate()->first();
            if (! $run) {
                return null;
            }
            $run->lease_token = (string) Str::uuid();
            DB::table('station_sales_sync_runs')->where('id', $run->id)->update([
                'status' => 'running', 'lease_token' => $run->lease_token,
                'lease_until' => now()->addSeconds((int) config('station_sales.lease_seconds')),
                'started_at' => $run->started_at ?? now(), 'updated_at' => now(),
            ]);

            return $run;
        });
    }

    /** Изолируется и подключение, и execute/fetch: зависший Firebird убивается ОС. */
    public function readPage(object $run): array
    {
        $process = $this->pageProcess($run);
        $process->setTimeout((int) config('station_sales.page_timeout'));
        try {
            $process->run();
        } catch (ProcessTimedOutException) {
            throw new \RuntimeException('Станция не вернула порцию за '.config('station_sales.page_timeout').' с. Прежние данные сохранены.');
        }
        if (! $process->isSuccessful()) {
            Log::warning('Не удалось прочитать порцию продаж станции', [
                'run_id' => $run->id, 'station' => $run->station_code,
                'details' => mb_substr($process->getErrorOutput(), 0, 2000),
            ]);
            throw new \RuntimeException('Не удалось прочитать продажи станции. Проверьте связь и журнал АИС. Прежние данные сохранены.');
        }
        $rows = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($rows) || ! array_is_list($rows) || count($rows) > (int) config('station_sales.page_size')) {
            throw new \RuntimeException('Станция вернула неверную порцию данных.');
        }

        return $rows;
    }

    protected function pageProcess(object $run): Process
    {
        return new Process([
            PHP_BINARY, '-d', 'memory_limit=128M', '-d', 'display_errors=stderr',
            base_path('artisan'), 'station-sales:read-page', (string) $run->id, '--no-ansi',
        ], base_path());
    }

    /** Публикуем только целиком собранные сутки; оборванная страница не меняет отчёт. */
    public function acceptPage(object $claimed, array $rows): void
    {
        DB::transaction(function () use ($claimed, $rows) {
            $run = DB::table('station_sales_sync_runs')->where('id', $claimed->id)->lockForUpdate()->first();
            if ($run?->lease_token !== $claimed->lease_token || $run->status !== 'running') {
                return;
            }
            $at = now()->toDateTimeString();
            $records = [];
            foreach ($rows as $row) {
                if (($row['DATESALE'] ?? null) !== $run->next_day) {
                    throw new \RuntimeException('В порции продаж получена другая дата.');
                }
                $records[] = ['run_id' => $run->id] + StationSalesSource::record($row, $run->station_code, $at);
            }
            if ($records) {
                DB::table('station_sales_staging')->upsert($records, ['run_id', 'record_key']);
            }
            $updates = [
                'rows_read' => $run->rows_read + count($rows), 'updated_at' => $at,
                'lease_until' => null, 'lease_token' => null,
            ];
            if (count($rows) === (int) config('station_sales.page_size')) {
                $last = end($rows);
                $updates['cursor'] = json_encode(array_intersect_key($last, array_flip(StationSalesSource::KEY)), JSON_THROW_ON_ERROR);
            } else {
                $staged = DB::table('station_sales_staging')->where('run_id', $run->id);
                DB::table('station_sales')->where('source_station', $run->station_code)->where('datesale', $run->next_day)->delete();
                // Глобальный PK как в SALE_ALL: реплики одной продажи не удваивают итог.
                DB::table('station_sales')->whereIn('record_key', (clone $staged)->select('record_key'))->delete();
                $columns = ['record_key', 'source_station', 'awtcod', 'awtcodsale', 'datesale', 'datestart',
                    'type_oper', 'kkm_ok', 'tick_back', 'price_place', 'price_bag', 'price_pl_isx', 'price_bag_isx', 'raw_data', 'synced_at'];
                DB::table('station_sales')->insertUsing($columns, (clone $staged)->select($columns));
                $staged->delete();
                DB::table('station_sales_sync_days')->upsert([
                    ['station_code' => $run->station_code, 'sale_date' => $run->next_day, 'updated_at' => $at],
                ], ['station_code', 'sale_date'], ['updated_at']);
                $updates['cursor'] = null;
                $updates['days_done'] = $run->days_done + 1;
                if ($run->next_day === $run->date_from) {
                    $updates['status'] = 'completed';
                    $updates['finished_at'] = $at;
                    if ($run->is_current) {
                        DB::table('station_sales_sync_states')->where('station_code', $run->station_code)->update([
                            // Время снимка — начало сбора, чтобы не обещать данные на момент завершения.
                            'current_updated_at' => $run->started_at,
                            'current_from' => $run->date_from, 'current_to' => $run->date_to,
                        ]);
                    }
                } else {
                    $updates['next_day'] = CarbonImmutable::parse($run->next_day)->subDay()->toDateString();
                }
            }
            DB::table('station_sales_sync_runs')->where('id', $run->id)->update($updates);
        });
    }

    public function fail(object $run, Throwable $error): void
    {
        DB::transaction(function () use ($run, $error) {
            $changed = DB::table('station_sales_sync_runs')->where('id', $run->id)->where('lease_token', $run->lease_token)->update([
                'status' => 'failed', 'error' => mb_substr($error->getMessage(), 0, 1000),
                'finished_at' => now(), 'updated_at' => now(), 'lease_token' => null, 'lease_until' => null,
            ]);
            if ($changed) {
                DB::table('station_sales_staging')->where('run_id', $run->id)->delete();
            }
        });
        Log::warning('Сбор продаж станции остановлен', ['run_id' => $run->id, 'station' => $run->station_code, 'error' => $error->getMessage()]);
    }
}
