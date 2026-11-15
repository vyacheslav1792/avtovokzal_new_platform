<?php

namespace App\Services;

use App\Models\StationSchedule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Регулярное расписание станций: обновление из API продаж и выдача сайту.
 *
 * В legacy расписание жило готовыми HTML-файлами: `getrasp.php?action=raspisanie`
 * обходил станции и перезаписывал url/raspisanie/<slug>.html, страница просто
 * подставляла файл. Здесь то же самое хранится в Postgres строкой на станцию
 * (jsonb), а сайт получает данные и рисует их сам.
 */
class ScheduleService
{
    public function __construct(private readonly SalesApiClient $salesApi) {}

    /**
     * Обновить расписание всех станций.
     *
     * Станция, которая не ответила, сохраняет прошлое расписание — на сайте
     * лучше вчерашние данные, чем пустая страница (в legacy файл тоже
     * оставался прежним).
     *
     * @return array<int, array{awtcod: int, name: string, routes: ?int, error: ?string}>
     */
    public function syncAll(): array
    {
        $result = [];

        foreach ($this->salesApi->stations()['data'] as $station) {
            $result[] = $this->sync((int) $station['awtcod'], (string) $station['name']);
        }

        return $result;
    }

    /** @return array{awtcod: int, name: string, routes: ?int, error: ?string} */
    public function sync(int $awtcod, string $name): array
    {
        try {
            $response = $this->salesApi->schedule($awtcod);

            StationSchedule::updateOrCreate(
                ['awtcod' => $awtcod],
                [
                    'station_name' => $response['awtname'] ?: $name,
                    'slug' => StationSchedule::slug($response['awtname'] ?: $name),
                    'routes' => $response['data'],
                    'routes_count' => count($response['data']),
                    // ЛОВУШКА ПОЯСА: API продаж живёт в UTC и отдаёт время
                    // со смещением (+00:00), а Eloquent пишет дату строкой
                    // БЕЗ смещения — сессия Postgres в Europe/Moscow примет
                    // её за московскую, и «актуально на» уедет на 3 часа
                    // назад (а для прогона после полуночи — и на сутки).
                    // Поэтому приводим к поясу приложения явно.
                    'generated_at' => isset($response['generated_at'])
                        ? Carbon::parse($response['generated_at'])->timezone(config('app.timezone'))
                        : now(),
                    'synced_at' => now(),
                    'sync_error' => null,
                ],
            );

            return ['awtcod' => $awtcod, 'name' => $name, 'routes' => count($response['data']), 'error' => null];
        } catch (\Throwable $e) {
            Log::warning('Не удалось обновить расписание станции', [
                'awtcod' => $awtcod,
                'error' => $e->getMessage(),
            ]);

            StationSchedule::where('awtcod', $awtcod)->update([
                'synced_at' => now(),
                'sync_error' => $e->getMessage(),
            ]);

            return ['awtcod' => $awtcod, 'name' => $name, 'routes' => null, 'error' => $e->getMessage()];
        }
    }

    /** Станции, у которых расписание уже сохранено (для списка на сайте). */
    public function stations(): array
    {
        return StationSchedule::orderBy('station_name')
            ->get(['awtcod', 'station_name', 'slug', 'routes_count', 'generated_at'])
            ->map(fn (StationSchedule $s) => [
                'awtcod' => (int) $s->awtcod,
                'name' => $s->station_name,
                'slug' => $s->slug,
                'routes_count' => (int) $s->routes_count,
                'generated_at' => $s->generated_at?->timezone(config('app.timezone'))->format('d.m.Y'),
            ])
            ->all();
    }

    /**
     * Состояние расписаний для админки: когда снято, когда обновлялось и
     * с какой ошибкой. Отличается от stations() тем, что сотруднику важны
     * именно отметки обновления, а сайту — маршруты.
     *
     * @return array<int, array<string, mixed>>
     */
    public function status(): array
    {
        return StationSchedule::orderBy('station_name')
            ->get()
            ->map(fn (StationSchedule $s) => [
                'awtcod' => (int) $s->awtcod,
                'name' => $s->station_name,
                'count' => (int) $s->routes_count,
                'generated_at' => $s->generated_at?->toIso8601String(),
                'synced_at' => $s->synced_at?->toIso8601String(),
                'sync_error' => $s->sync_error,
            ])
            ->all();
    }

    /** Расписание станции по коду, названию или slug. */
    public function find(int|string $station): ?StationSchedule
    {
        $query = StationSchedule::query();

        if (is_numeric($station)) {
            return $query->where('awtcod', (int) $station)->first();
        }

        return $query
            ->where('station_name', $station)
            ->orWhere('slug', StationSchedule::slug($station))
            ->first();
    }
}
