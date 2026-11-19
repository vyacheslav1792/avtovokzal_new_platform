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

        return ['date1' => $today->subDays($days - 1)->toDateString(), 'date2' => $today->toDateString(), 'lookback_days' => $days, 'chunk_days' => $this->settings->stationSalesChunkDays()];
    }

    public function start(?array $codes = null, ?string $date1 = null, ?string $date2 = null, ?string $scheduledOn = null): array
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
        $includeOutsidePeriod = $date1 === null;
        $date1 ??= $current['date1'];
        $date2 ??= $current['date2'];
        validator(['date1' => $date1, 'date2' => $date2], [
            'date1' => ['required', 'date_format:Y-m-d', 'before_or_equal:date2', 'after_or_equal:1900-01-01'],
            'date2' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.$current['date2']],
        ])->validate();

        $chunkDays = $this->settings->stationSalesChunkDays();
        $refundDays = $this->refundDays($codes);
        $created = $busy = [];
        foreach ($codes as $code) {
            $id = DB::transaction(function () use ($code, $date1, $date2, $current, $scheduledOn, $chunkDays, $refundDays, $includeOutsidePeriod) {
                DB::table('station_sales_sync_states')->insertOrIgnore(['station_code' => $code]);
                // Один незавершённый сбор на станцию, включая параллельные HTTP/cron.
                $state = DB::table('station_sales_sync_states')->where('station_code', $code);
                $lastScheduled = (clone $state)->lockForUpdate()->first()->last_scheduled_on;
                if ($scheduledOn !== null && $lastScheduled === $scheduledOn) {
                    return null;
                }
                if (DB::table('station_sales_sync_runs')->where('station_code', $code)
                    ->whereIn('status', ['queued', 'running'])->exists()) {
                    return null;
                }

                if ($scheduledOn !== null) {
                    $state->update(['last_scheduled_on' => $scheduledOn]);
                }

                $dates = $refundDays[$code] ?? [];
                if (! $includeOutsidePeriod) {
                    $dates = array_filter($dates, fn ($day) => $day >= $date1 && $day <= $date2, ARRAY_FILTER_USE_KEY);
                }
                $applied = DB::table('station_sales_sync_days')->where('station_code', $code)
                    ->whereIn('sale_date', array_keys($dates))->pluck('refund_signature', 'sale_date')->all();
                $outstanding = array_diff_assoc($dates, $applied);
                krsort($outstanding);
                return DB::table('station_sales_sync_runs')->insertGetId([
                    'station_code' => $code, 'status' => 'queued', 'chunk_days' => $chunkDays,
                    'is_current' => $date1 <= $current['date1'] && $date2 === $current['date2'],
                    'date_from' => $date1, 'date_to' => $date2, 'next_day' => $date2,
                    'refund_days' => json_encode($outstanding, JSON_THROW_ON_ERROR),
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

    /** Снимок подтверждённых досылок. При повторе в те же сутки меняется подпись дня. */
    private function refundDays(array $codes): array
    {
        $refunds = DB::connection('site')->table('refunds as r')->join('orders as o', 'o.id', '=', 'r.order_id')
            ->whereIn('o.awtcod', $codes)->whereNotNull('r.station_annul_attempted_at')
            ->whereNotNull('r.station_annulled_at')->where('r.station_annul_pending', false)
            ->orderBy('r.id')->select(['r.id', 'o.awtcod', 'r.station_annulled_at', 'r.station_backdated_at',
                'r.money_refunded_at', 'r.created_at'])->cursor();
        $events = [];
        foreach ($refunds as $refund) {
            $dates = [$refund->station_annulled_at];
            if ($refund->station_backdated_at !== null) {
                // При досылке до успешной выплаты используется created_at.
                // Банк мог заполнить money_refunded_at уже после переноса SALE.
                $dates[] = $refund->created_at;
                if ($refund->money_refunded_at !== null) {
                    $dates[] = $refund->money_refunded_at;
                }
            }
            $days = array_unique(array_map(fn ($date) => CarbonImmutable::parse($date)->setTimezone('Europe/Moscow')->toDateString(), $dates));
            foreach ($days as $day) {
                $events[$refund->awtcod][$day][] = $refund->id;
            }
        }
        foreach ($events as $code => $dates) {
            foreach ($dates as $day => $ids) {
                $events[$code][$day] = hash('sha256', implode(',', $ids));
            }
        }

        return $events;
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
            if ($run && $run->refund_days) {
                $days += count(StationSalesSource::extraDays($run));
            }
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
            $updates = [];
            if ($run->chunk_days === null) {
                // Незавершённая строковая пагинация не покрывает текущую порцию целиком.
                DB::table('station_sales_staging')->where('run_id', $run->id)->delete();
                DB::table('station_sales_sync_seen')->where('run_id', $run->id)->delete();
                $updates = [
                    'chunk_days' => $this->settings->stationSalesChunkDays(), 'cursor' => null,
                    'next_day' => $run->date_to, 'days_done' => 0, 'rows_read' => 0,
                    'read_failures' => 0, 'error' => null, 'started_at' => now(),
                ];
            }
            $run->lease_token = (string) Str::uuid();
            DB::table('station_sales_sync_runs')->where('id', $run->id)->update($updates + [
                'status' => 'running', 'lease_token' => $run->lease_token,
                'lease_until' => now()->addSeconds((int) config('station_sales.lease_seconds')),
                'started_at' => $run->started_at ?? now(), 'updated_at' => now(),
            ]);

            return (object) ($updates + (array) $run);
        });
    }

    public static function chunk(object $run): array
    {
        $first = CarbonImmutable::parse($run->date_from);
        $last = CarbonImmutable::parse($run->date_to);
        $days = (int) $first->diffInDays($last) + 1;
        if ($run->days_done < $days) {
            $end = $last->subDays($run->days_done);
            $count = min($run->chunk_days, $days - $run->days_done);

            return ['from' => $end->subDays($count - 1)->toDateString(), 'to' => $end->toDateString(),
                'extra' => [], 'count' => $count];
        }
        $extra = array_slice(StationSalesSource::extraDays($run), $run->days_done - $days, $run->chunk_days);

        return ['from' => null, 'to' => null, 'extra' => $extra, 'count' => count($extra)];
    }

    /** Дочерний процесс читает Firebird с дедлайном и пишет строки на диск. */
    public function readChunk(object $run): \Generator
    {
        $spool = tmpfile();
        if ($spool === false) {
            throw new \RuntimeException('Не удалось создать временный файл порции продаж.');
        }
        try {
            $process = $this->chunkProcess($run, stream_get_meta_data($spool)['uri']);
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

            return $this->decodedRows($spool);
        } catch (Throwable $error) {
            fclose($spool);

            throw $error;
        }
    }

    private function decodedRows($spool): \Generator
    {
        try {
            while (($line = fgets($spool)) !== false) {
                $row = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
                if (! is_array($row)) {
                    throw new \RuntimeException('Станция вернула неверную запись.');
                }
                yield $row;
            }
            if (! feof($spool)) {
                throw new \RuntimeException('Не удалось прочитать временный файл порции продаж.');
            }
        } finally {
            fclose($spool);
        }
    }

    protected function chunkProcess(object $run, string $output): Process
    {
        return new Process([
            PHP_BINARY, '-d', 'memory_limit=128M', '-d', 'display_errors=stderr',
            base_path('artisan'), 'station-sales:read-page', (string) $run->id,
            '--output='.$output, '--lease='.$run->lease_token, '--no-ansi',
        ], base_path());
    }

    /** При ошибке сохраняем опубликованный период и уже принятые порции задания. */
    public function acceptChunk(object $claimed, iterable $rows): void
    {
        DB::transaction(function () use ($claimed, $rows) {
            $run = DB::table('station_sales_sync_runs')->where('id', $claimed->id)->lockForUpdate()->first();
            if ($run?->lease_token !== $claimed->lease_token || $run->status !== 'running') {
                return;
            }

            $chunk = self::chunk($run);
            $at = now()->toDateTimeString();
            $read = 0;
            $records = [];
            foreach ($rows as $row) {
                $record = StationSalesSource::record($row, $run->station_code, $at);
                $day = $record['datesale'];
                $inChunk = $chunk['extra'] === []
                    ? $day >= $chunk['from'] && $day <= $chunk['to']
                    : in_array($day, $chunk['extra'], true);
                if (! $inChunk && (int) $record['type_oper'] !== 0) {
                    throw new \RuntimeException('В порции продаж получена другая дата.');
                }
                $records[$record['record_key']] = ['run_id' => $run->id] + $record;
                $read++;
                if (count($records) === 500) {
                    DB::table('station_sales_staging')->upsert(array_values($records), ['run_id', 'record_key']);
                    $records = [];
                }
            }
            if ($records !== []) {
                DB::table('station_sales_staging')->upsert(array_values($records), ['run_id', 'record_key']);
            }

            $daysDone = $run->days_done + $chunk['count'];
            $updates = [
                'read_failures' => 0, 'error' => null, 'days_done' => $daysDone,
                'rows_read' => $run->rows_read + $read, 'updated_at' => $at,
                'lease_until' => null, 'lease_token' => null,
            ];
            $run->days_done = $daysDone;
            $next = self::chunk($run);
            if ($next['count'] !== 0) {
                $updates['next_day'] = $next['to'] ?? $next['extra'][0];
            } else {
                $this->publish($run, $at);
                $updates['status'] = 'completed';
                $updates['finished_at'] = $at;
            }
            DB::table('station_sales_sync_runs')->where('id', $run->id)->update($updates);
        });
    }

    private function publish(object $run, string $at): void
    {
        $extra = StationSalesSource::extraDays($run);
        $staged = DB::table('station_sales_staging')->where('run_id', $run->id);
        DB::table('station_sales')->where('source_station', $run->station_code)
            ->where(fn ($q) => $q->whereBetween('datesale', [$run->date_from, $run->date_to])->orWhereIn('datesale', $extra))
            ->delete();
        // Глобальный ключ исключает дубликаты общей продажи из разных источников.
        DB::table('station_sales')->whereIn('record_key', (clone $staged)->select('record_key'))->delete();
        $columns = ['record_key', 'source_station', 'awtcod', 'awtcodsale', 'datesale', 'datestart',
            'type_oper', 'kkm_ok', 'tick_back', 'price_place', 'price_bag', 'price_pl_isx', 'price_bag_isx', 'raw_data', 'synced_at'];
        DB::table('station_sales')->insertUsing($columns, (clone $staged)->select($columns));
        $staged->delete();
        $refundDays = json_decode($run->refund_days ?? '[]', true, flags: JSON_THROW_ON_ERROR);
        $this->publishDates($run, $extra, $refundDays, $at);
        if ($run->is_current) {
            DB::table('station_sales_sync_states')->where('station_code', $run->station_code)->update([
                'current_updated_at' => $run->started_at,
                'current_from' => $run->date_from, 'current_to' => $run->date_to,
            ]);
        }
    }

    private function publishDates(object $run, array $extra, array $refundDays, string $at): void
    {
        $dates = $extra;
        for ($date = CarbonImmutable::parse($run->date_from); $date->toDateString() <= $run->date_to; $date = $date->addDay()) {
            $dates[] = $date->toDateString();
        }
        foreach (array_chunk($dates, 500) as $batch) {
            $rows = array_map(fn ($day) => ['station_code' => $run->station_code, 'sale_date' => $day, 'updated_at' => $at], $batch);
            DB::table('station_sales_sync_days')->upsert($rows, ['station_code', 'sale_date'], ['updated_at']);
        }
        foreach (array_chunk($refundDays, 500, true) as $batch) {
            $rows = [];
            foreach ($batch as $day => $signature) {
                $rows[] = ['station_code' => $run->station_code, 'sale_date' => $day, 'updated_at' => $at,
                    'refund_signature' => $signature];
            }
            DB::table('station_sales_sync_days')->upsert($rows, ['station_code', 'sale_date'], ['refund_signature']);
        }
    }

    public function retryRead(object $run, Throwable $error): void
    {
        if ($run->read_failures >= 2) {
            $this->fail($run, $error);

            return;
        }

        DB::table('station_sales_sync_runs')->where('id', $run->id)->where('lease_token', $run->lease_token)->update([
            'read_failures' => $run->read_failures + 1, 'lease_token' => null,
            'lease_until' => now()->addMinute(), 'updated_at' => now(),
            'error' => 'Повтор чтения через минуту. '.mb_substr($error->getMessage(), 0, 900),
        ]);
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
