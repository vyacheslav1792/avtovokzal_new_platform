<?php

namespace App\Services;

use App\Models\StationSchedule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
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
    public function __construct(
        private readonly SalesApiClient $salesApi,
        private readonly BoardingPointCatalog $boardingPoints,
    ) {}

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

        /*
         * Пункты посадки: у них своей базы нет, и расписание собирается из
         * баз станций, которые ведут проходящие рейсы. Делаем это тем же
         * прогоном — иначе расписание остановки отставало бы от
         * станционного (docs/BOARDING_POINTS.md).
         */
        foreach ($this->boardingPoints->forSchedule() as $point) {
            $result[] = $this->syncBoardingPoint($point);
        }

        return $result;
    }

    /**
     * Расписание пункта посадки — объединение рейсов, проходящих через
     * остановку, по всем его станциям.
     *
     * Маршруты одной станции с другой не смешиваются: рейс ведёт та база,
     * в которой он заведён, и время прохождения остановки берётся из её
     * расписания остановок. Молчание одной станции не стирает собранное:
     * тогда сохраняется то, что ответили остальные, а в журнал идёт
     * предупреждение — пустое расписание на сайте хуже неполного.
     *
     * @param  array<string, mixed>  $point
     * @return array{awtcod: ?int, name: string, routes: ?int, error: ?string}
     */
    public function syncBoardingPoint(array $point): array
    {
        $slug = (string) $point['slug'];
        $name = (string) $point['name'];

        $saved = StationSchedule::where('boarding_point_slug', $slug)->first();

        // Автосборка выключена — расписание остановки ведут вручную.
        if ($this->autoDisabled($saved)) {
            return ['awtcod' => null, 'name' => $name, 'routes' => null, 'error' => null];
        }

        $routes = [];
        $failed = [];

        foreach ($point['stations'] as $binding) {
            try {
                $response = $this->salesApi->schedule((int) $binding['awtcod'], (string) $binding['station_name']);
                $routes = array_merge($routes, $response['data'] ?? []);
            } catch (\Throwable $e) {
                $failed[] = $binding['awtcod'];
                Log::warning('Станция не ответила на расписание от остановки', [
                    'slug' => $slug,
                    'awtcod' => $binding['awtcod'],
                    'station_name' => $binding['station_name'],
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if ($routes === []) {
            $error = $failed === []
                ? 'Через остановку не проходит ни один рейс регулярного расписания'
                : 'Станции не ответили: '.implode(', ', $failed);

            /*
             * Автоматической части нет — но у пункта могут быть маршруты,
             * дописанные вручную (рейсы, которые не заходят ни на одну
             * станцию из привязок). Ради пустого ответа баз их терять
             * нельзя: страница должна остаться с тем, что есть.
             */
            StationSchedule::where('boarding_point_slug', $slug)->update([
                'synced_at' => now(),
                'sync_error' => $error,
            ]);

            return ['awtcod' => null, 'name' => $name, 'routes' => null, 'error' => $error];
        }

        // Рейсы разных станций идут общим списком — по времени, как их и
        // читают: «когда отсюда уходит следующий автобус».
        usort($routes, fn (array $a, array $b) => strcmp(
            $a['departures'][0]['time'] ?? '', $b['departures'][0]['time'] ?? '',
        ));

        StationSchedule::updateOrCreate(
            ['boarding_point_slug' => $slug],
            [
                'awtcod' => null,
                'station_name' => $name,
                'slug' => $slug,
                // Пишем ТОЛЬКО автоматическую часть: ручные маршруты
                // живут в `manual_routes` и синком не трогаются.
                'routes' => $routes,
                'routes_count' => count($routes),
                'is_manual' => false,
                'generated_at' => now(),
                'synced_at' => now(),
                'sync_error' => $failed === [] ? null : 'Не ответили станции: '.implode(', ', $failed),
            ],
        );

        return [
            'awtcod' => null,
            'name' => $name,
            'routes' => count($routes),
            'error' => $failed === [] ? null : 'часть станций не ответила',
        ];
    }

    /** @return array{awtcod: int, name: string, routes: ?int, error: ?string} */
    public function sync(int $awtcod, string $name): array
    {
        /*
         * Автосборка выключена в АИС — место пропускаем: его расписание
         * ведут полностью вручную (база отдаёт то, чему нельзя верить).
         * Ручную половину синк и так не трогает — она лежит отдельно, в
         * `manual_routes` (docs/BOARDING_POINTS.md).
         */
        if ($this->autoDisabled(StationSchedule::where('awtcod', $awtcod)->first())) {
            return [
                'awtcod' => $awtcod,
                'name' => $name,
                'routes' => null,
                'error' => null,
                'skipped' => 'автосборка выключена',
            ];
        }

        try {
            $response = $this->salesApi->schedule($awtcod);

            StationSchedule::updateOrCreate(
                ['awtcod' => $awtcod],
                [
                    'station_name' => $response['awtname'] ?: $name,
                    'slug' => StationSchedule::slug($response['awtname'] ?: $name),
                    'routes' => $response['data'],
                    'routes_count' => count($response['data']),
                    // ЛОВУШКА ПОЯСА: API продаж отдаёт время строкой СО
                    // смещением, а Eloquent пишет дату строкой БЕЗ него —
                    // сессия Postgres в Europe/Moscow примет её за
                    // московскую. Пока API продаж жил в UTC, «актуально на»
                    // уезжало на 3 часа назад (а для прогона после полуночи
                    // — и на сутки). Приводим к поясу приложения явно:
                    // так правка верна при любом поясе источника.
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

    /**
     * Станции, у которых расписание уже сохранено (для списка на сайте).
     *
     * Станции, у которых в АИС снят признак «Показывать в расписании», из
     * списка убираются: закрытую станцию иногда надо спрятать целиком, а
     * расписание при этом сохранить (вернут признак — вернётся и
     * страница). Остальные — включая закрытые с ручным расписанием —
     * остаются: в legacy их страницы тоже открывались,
     * см. docs/FRONTEND.md.
     */
    public function stations(): array
    {
        $hidden = $this->hiddenInSchedule();

        return StationSchedule::orderBy('station_name')
            ->get()
            ->reject(fn (StationSchedule $s) => $s->awtcod !== null
                && in_array((int) $s->awtcod, $hidden, true))
            ->map(fn (StationSchedule $s) => [
                'awtcod' => $s->awtcod === null ? null : (int) $s->awtcod,
                /*
                 * Расписание пункта посадки (у Дросково своей станции
                 * нет): на сайте это тот же пункт, что и в поиске
                 * билетов, поэтому в списке он один, а не «пункт» и
                 * «станция» отдельно (docs/BOARDING_POINTS.md).
                 */
                'boarding_point' => $s->boarding_point_slug,
                'name' => $s->station_name,
                'slug' => $s->slug,
                // Авто + ручные: в списке станций должно стоять то же
                // число, что человек увидит на странице.
                'routes_count' => count($s->allRoutes()),
                'generated_at' => $s->generated_at?->timezone(config('app.timezone'))->format('d.m.Y'),
            ])
            ->values()
            ->all();
    }

    /**
     * Автосборка выключена для этого места.
     *
     * Старый признак `is_manual` учитываем тоже: записи, заведённые до
     * разделения половин, означали «всё расписание ручное» — то же, что
     * выключенная автосборка.
     */
    private function autoDisabled(?StationSchedule $schedule): bool
    {
        return (bool) ($schedule?->auto_disabled || $schedule?->is_manual);
    }

    /**
     * Станции, спрятанные из раздела расписания (признак в справочнике
     * АИС). Справочник читаем прямым запросом — так же, как права
     * сотрудников и пункты посадки; недоступная база АИС не должна
     * гасить расписание, поэтому при ошибке не скрываем никого.
     *
     * @return array<int, int>
     */
    private function hiddenInSchedule(): array
    {
        try {
            return Cache::remember('schedule:hidden-stations', 60, fn () => DB::connection('ais')
                ->table('stations')
                ->where('show_in_schedule', false)
                ->pluck('awtcod')
                ->map(fn ($code) => (int) $code)
                ->all());
        } catch (\Throwable) {
            return [];
        }
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
                // Ручное расписание синк не обновляет — об этом сотруднику
                // стоит знать, иначе «не обновлялось» читается как сбой.
                'is_manual' => (bool) $s->is_manual,
                'generated_at' => $s->generated_at?->toIso8601String(),
                'synced_at' => $s->synced_at?->toIso8601String(),
                'sync_error' => $s->sync_error,
            ])
            ->all();
    }

    /**
     * Расписание станции по коду, названию или slug.
     *
     * Станции со снятым признаком «Показывать в расписании» не отдаём
     * вовсе: иначе страница открывалась бы по прямой ссылке, хотя из
     * раздела станцию убрали, — а признак для этого и снимают.
     */
    public function find(int|string $station): ?StationSchedule
    {
        $query = StationSchedule::query();

        $schedule = is_numeric($station)
            ? $query->where('awtcod', (int) $station)->first()
            : $query
                ->where('station_name', $station)
                ->orWhere('slug', StationSchedule::slug($station))
                // Пункт посадки ищется и по своему ключу: страница сайта
                // открывается как `?from=Дросково`, а АИС знает его slug.
                ->orWhere('boarding_point_slug', $station)
                ->first();

        if ($schedule === null) {
            return null;
        }

        // Признак «Показывать в расписании» есть только у станций; у
        // пункта посадки его роль играет его собственный выключатель.
        if ($schedule->awtcod === null) {
            return $schedule;
        }

        return in_array((int) $schedule->awtcod, $this->hiddenInSchedule(), true) ? null : $schedule;
    }
}
