<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BoardingPoint;
use App\Models\Station;
use App\Models\StationSchedule;
use App\Services\StationRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Редактор регулярного расписания станций.
 *
 * Нужен станциям, у которых базы продаж больше НЕТ: касса снята, станция
 * закрыта, а автобусы через неё идут — людям нужно расписание. В legacy
 * для них лежал готовый HTML-файл (`url/raspisanie/<slug>.html`), и его
 * правили текстовым редактором прямо на боевом сервере; при любой правке
 * графика это приходилось делать вручную и без проверок.
 *
 * Теперь маршруты хранятся так же, как у станций с базой (`routes` в
 * `station_schedules`, БД сайта), и правятся формой. Признак `is_manual`
 * защищает их от `schedule:sync`: иначе первый же прогон обнулил бы
 * работу (станция в справочнике есть, база не отвечает).
 *
 * Правится ВСЕГДА только ручная половина (`manual_routes`): расписание
 * из базы станции (`routes`) обновляет `schedule:sync`, и затирать его
 * здесь нельзя. Поэтому дописать рейс «для справки» можно и станции с
 * работающей базой — например автобус другого перевозчика, которого в
 * BUSTER нет.
 */
class ScheduleEditorController extends Controller
{
    public function __construct(private readonly StationRegistry $stations) {}

    /** Дни недели в том же виде, в каком их отдаёт API продаж. */
    private const DAYS = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];

    public function index(): JsonResponse
    {
        $schedules = StationSchedule::orderBy('station_name')->get();
        $stations = Station::orderBy('inlist')->orderBy('awtcod')->get();

        $rows = $stations->map(function (Station $station) use ($schedules) {
            $schedule = $schedules->firstWhere('awtcod', $station->awtcod);

            return [
                'awtcod' => $station->awtcod,
                'name' => $station->name,
                'is_active' => $station->is_active,
                'show_in_schedule' => $station->show_in_schedule,
                // Есть ли у станции рабочее подключение: от этого зависит,
                // придёт ли расписание из BUSTER само.
                'has_connection' => $station->activeProfile() !== null,
                'slug' => $schedule?->slug ?? StationSchedule::slug($station->name),
                'routes_count' => count($schedule?->routes ?? []) + count($schedule?->manual_routes ?? []),
                'auto_count' => count($schedule?->routes ?? []),
                'manual_count' => count($schedule?->manual_routes ?? []),
                'auto_disabled' => (bool) ($schedule?->auto_disabled ?? false),
                'generated_at' => $schedule?->generated_at?->format('d.m.Y H:i'),
                'synced_at' => $schedule?->synced_at?->format('d.m.Y H:i'),
                'sync_error' => $schedule?->sync_error,
                'edited_by' => $schedule?->edited_by,
                'note' => $schedule?->note,
            ];
        });

        /*
         * ПУНКТЫ ПОСАДКИ — остановки, с которых продаёт сайт. Своей
         * автостанции у них нет (у Дросково её закрыли), поэтому
         * расписание проходящих автобусов им заводят здесь же, а на сайте
         * пункт остаётся ОДНОЙ сущностью: и в «Откуда», и в разделе
         * расписания (docs/BOARDING_POINTS.md).
         */
        $points = BoardingPoint::orderBy('inlist')->orderBy('name')->get()
            ->map(function (BoardingPoint $point) use ($schedules) {
                $schedule = $schedules->firstWhere('boarding_point_slug', $point->slug);

                return [
                    'awtcod' => null,
                    'boarding_point' => $point->slug,
                    'name' => $point->name,
                    'is_active' => $point->is_active,
                    'show_in_schedule' => $point->show_in_schedule,
                    // Базы продаж у пункта нет по определению: рейсы ведут
                    // соседние станции, поэтому расписание только ручное.
                    'has_connection' => false,
                    'slug' => $schedule?->slug ?? $point->slug,
                    'routes_count' => count($schedule?->routes ?? []) + count($schedule?->manual_routes ?? []),
                    'auto_count' => count($schedule?->routes ?? []),
                    'manual_count' => count($schedule?->manual_routes ?? []),
                    'auto_disabled' => (bool) ($schedule?->auto_disabled ?? false),
                    'is_manual' => (bool) ($schedule?->is_manual ?? false),
                    'generated_at' => $schedule?->generated_at?->format('d.m.Y H:i'),
                    'synced_at' => $schedule?->synced_at?->format('d.m.Y H:i'),
                    'sync_error' => $schedule?->sync_error,
                    'edited_by' => $schedule?->edited_by,
                    'note' => $schedule?->note,
                ];
            });

        /*
         * Расписания станций, которых в справочнике уже нет (станцию
         * удалили, а расписание осталось): их тоже надо показать, иначе
         * страница сайта живёт, а в АИС её не видно.
         */
        $orphans = $schedules
            ->whereNull('boarding_point_slug')
            ->whereNotIn('awtcod', $stations->pluck('awtcod'))
            ->map(fn (StationSchedule $s) => [
                'awtcod' => (int) $s->awtcod,
                'name' => $s->station_name,
                'is_active' => false,
                'show_in_schedule' => true,
                'has_connection' => false,
                'slug' => $s->slug,
                'routes_count' => count($s->routes ?? []) + count($s->manual_routes ?? []),
                'auto_count' => count($s->routes ?? []),
                'manual_count' => count($s->manual_routes ?? []),
                'auto_disabled' => (bool) $s->auto_disabled,
                'generated_at' => $s->generated_at?->format('d.m.Y H:i'),
                'synced_at' => $s->synced_at?->format('d.m.Y H:i'),
                'sync_error' => $s->sync_error,
                'edited_by' => $s->edited_by,
                'note' => $s->note,
                'orphan' => true,
            ])
            ->values();

        return response()->json([
            'success' => true,
            'data' => [
                'stations' => $rows->concat($points)->concat($orphans)->values()->all(),
                'days' => self::DAYS,
            ],
        ]);
    }

    /** Расписание пункта посадки — его правит та же форма. */
    public function showPoint(string $slug): JsonResponse
    {
        $point = BoardingPoint::where('slug', $slug)->first();

        if ($point === null) {
            return response()->json(['success' => false, 'error' => 'Пункт посадки не найден'], 404);
        }

        $schedule = StationSchedule::where('boarding_point_slug', $slug)->first();

        return response()->json([
            'success' => true,
            'data' => [
                'awtcod' => null,
                'boarding_point' => $point->slug,
                'name' => $point->name,
                'slug' => $schedule?->slug ?? $point->slug,
                'is_manual' => (bool) ($schedule?->is_manual ?? false),
                'has_connection' => false,
                'auto_disabled' => (bool) ($schedule?->auto_disabled ?? false),
                'note' => $schedule?->note,
                'edited_by' => $schedule?->edited_by,
                'generated_at' => $schedule?->generated_at?->toIso8601String(),
                /*
                 * Две части расписания остановки:
                 *  - `auto_routes` собраны из баз станций, которые ведут
                 *    проходящие рейсы, и правке не подлежат — их перезапишет
                 *    следующий `schedule:sync`;
                 *  - `routes` — дописанные вручную: рейсы, которые не
                 *    заходят ни на одну станцию из привязок, а для справки
                 *    нужны. Их и правит форма.
                 */
                'auto_routes' => $schedule?->routes ?? [],
                'routes' => $schedule?->manual_routes ?? [],
            ],
        ]);
    }

    /**
     * Сохранить расписание пункта посадки.
     *
     * Проверок «а не приходит ли оно из базы» здесь нет и быть не может:
     * своей базы продаж у пункта нет вовсе — расписание всегда ручное.
     */
    public function updatePoint(Request $request, string $slug): JsonResponse
    {
        $point = BoardingPoint::where('slug', $slug)->first();

        if ($point === null) {
            return response()->json(['success' => false, 'error' => 'Пункт посадки не найден'], 404);
        }

        $data = $this->validateRoutes($request);
        $routes = $this->normalize($data['routes']);
        $existing = StationSchedule::where('boarding_point_slug', $point->slug)->first();
        $autoDisabled = (bool) ($data['auto_disabled'] ?? ($existing?->auto_disabled ?? false));

        $saved = StationSchedule::updateOrCreate(
            ['boarding_point_slug' => $point->slug],
            [
                'awtcod' => null,
                'station_name' => $point->name,
                // Адрес страницы на сайте — ключ пункта: он же стоит в
                // «Откуда», и на сайте это одно и то же место.
                'slug' => $point->slug,
                /*
                 * Пишем ТОЛЬКО ручную часть. Автоматическую (`routes`)
                 * собирает `schedule:sync` из баз станций, и затирать её
                 * здесь нельзя — иначе расписание остановки теряло бы
                 * проходящие рейсы до следующего прогона.
                 */
                'manual_routes' => $routes,
                'is_manual' => false,
                'auto_disabled' => $autoDisabled,
                'routes' => $autoDisabled ? [] : ($existing?->routes ?? []),
                'edited_by' => $request->user()?->full_name ?: $request->user()?->login,
                'note' => $data['note'] ?? null,
                'generated_at' => now(),
                'synced_at' => now(),
                'sync_error' => null,
            ],
        );

        return response()->json([
            'success' => true,
            'data' => [
                'boarding_point' => $point->slug,
                'slug' => $saved->slug,
                'manual_count' => count($routes),
                'auto_count' => count($saved->routes ?? []),
                'generated_at' => $saved->generated_at?->toIso8601String(),
            ],
        ]);
    }

    /**
     * Убрать РУЧНЫЕ маршруты пункта посадки.
     *
     * Автоматическую часть так не удалить: её соберёт следующий
     * `schedule:sync` из баз станций. Чтобы убрать остановку из раздела
     * расписания целиком, снимают признак «Показывать в расписании» у
     * самого пункта.
     */
    public function destroyPoint(string $slug): JsonResponse
    {
        $schedule = StationSchedule::where('boarding_point_slug', $slug)->first();

        if ($schedule === null) {
            return response()->json(['success' => false, 'error' => 'Расписание не найдено'], 404);
        }

        if (($schedule->manual_routes ?? []) === []) {
            return response()->json([
                'success' => false,
                'error' => 'Расписание собрано из баз станций — вручную добавленных маршрутов нет.'
                    .' Чтобы убрать остановку из раздела, снимите у неё признак «Показывать в расписании».',
                'error_code' => 'schedule_is_automatic',
            ], 422);
        }

        $schedule->update(['manual_routes' => null, 'edited_by' => request()->user()?->login]);

        return response()->json(['success' => true]);
    }

    /** Расписание одной станции целиком — его и правит форма. */
    public function show(int $awtcod): JsonResponse
    {
        $schedule = StationSchedule::where('awtcod', $awtcod)->first();
        $station = Station::find($awtcod);

        if ($schedule === null && $station === null) {
            return response()->json([
                'success' => false,
                'error' => 'Станция не найдена ни в справочнике, ни в расписаниях',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'awtcod' => $awtcod,
                'name' => $schedule?->station_name ?? $station->name,
                'slug' => $schedule?->slug ?? StationSchedule::slug($station->name),
                'has_connection' => $station?->activeProfile() !== null,
                // Выключатель автосборки: с ним синк это место пропускает.
                'auto_disabled' => (bool) ($schedule?->auto_disabled ?? false),
                'note' => $schedule?->note,
                'edited_by' => $schedule?->edited_by,
                'generated_at' => $schedule?->generated_at?->toIso8601String(),
                /*
                 * Две половины расписания. `auto_routes` приходят из базы
                 * станции (их перезапишет следующий `schedule:sync`),
                 * `routes` — дописанные вручную: рейсы, которых в базе нет,
                 * а для справки нужны (docs/BOARDING_POINTS.md). Форма
                 * правит только вторую половину — у любой станции, с
                 * работающей базой или без.
                 */
                'auto_routes' => $schedule?->routes ?? [],
                'routes' => $schedule?->manual_routes ?? [],
            ],
        ]);
    }

    /**
     * Сохранить расписание вручную.
     *
     * Станцию с рабочим подключением так править нельзя: её расписание
     * обновляет `schedule:sync` из базы, и ручная правка либо исчезнет при
     * следующем прогоне, либо (при `is_manual`) навсегда разойдётся с
     * тем, что станция реально продаёт.
     */
    public function update(Request $request, int $awtcod): JsonResponse
    {
        $station = Station::find($awtcod);
        $schedule = StationSchedule::where('awtcod', $awtcod)->first();

        if ($station === null && $schedule === null) {
            return response()->json([
                'success' => false,
                'error' => 'Станция не найдена ни в справочнике, ни в расписаниях',
            ], 404);
        }

        $data = $this->validateRoutes($request);

        $name = $schedule?->station_name ?? $station->name;
        $routes = $this->normalize($data['routes']);
        $autoDisabled = (bool) ($data['auto_disabled'] ?? ($schedule?->auto_disabled ?? false));
        $saved = $schedule;

        $saved = StationSchedule::updateOrCreate(
            ['awtcod' => $awtcod],
            [
                'station_name' => $name,
                'slug' => StationSchedule::slug($name),
                /*
                 * Только ручная половина: автоматическую пишет
                 * `schedule:sync` из базы станции, и затирать её здесь
                 * нельзя — расписание потеряло бы все рейсы до
                 * следующего прогона.
                 */
                'manual_routes' => $routes,
                'is_manual' => false,
                'auto_disabled' => $autoDisabled,
                // Выключили автосборку — собранное больше не показываем.
                'routes' => $autoDisabled ? [] : ($saved?->routes ?? []),
                // Кто правил: в журнале расписания это единственный след.
                'edited_by' => $request->user()?->full_name ?: $request->user()?->login,
                'note' => $data['note'] ?? null,
                /*
                 * «Актуально на» двигаем только когда автоматической
                 * половины нет: иначе дата перестанет говорить о свежести
                 * станционных данных.
                 */
                'generated_at' => ($saved?->routes ?? []) === [] ? now() : $saved?->generated_at,
            ],
        );

        return response()->json([
            'success' => true,
            'data' => [
                'awtcod' => $awtcod,
                'slug' => $saved->slug,
                'manual_count' => count($routes),
                'auto_count' => count($saved->routes ?? []),
                'generated_at' => $saved->generated_at?->toIso8601String(),
            ],
        ]);
    }

    /**
     * Убрать расписание станции с сайта.
     *
     * Для ручного это единственный способ его снять (страница станции
     * исчезнет из раздела расписания). Автоматическое удалять незачем —
     * его вернёт следующий прогон синка, поэтому отказываем.
     */
    public function destroy(int $awtcod): JsonResponse
    {
        $schedule = StationSchedule::where('awtcod', $awtcod)->first();

        if ($schedule === null) {
            return response()->json(['success' => false, 'error' => 'Расписание не найдено'], 404);
        }

        if (($schedule->manual_routes ?? []) === []) {
            return response()->json([
                'success' => false,
                'error' => 'Вручную добавленных маршрутов нет, а собранные из базы станции вернёт'
                    .' следующее обновление. Чтобы убрать станцию из раздела, снимите признак'
                    .' «Показывать в расписании».',
                'error_code' => 'schedule_is_automatic',
            ], 422);
        }

        // Удаляем только ручную половину; записи целиком не трогаем —
        // в ней живёт расписание из базы станции.
        $schedule->update([
            'manual_routes' => null,
            'edited_by' => request()->user()?->full_name ?: request()->user()?->login,
        ]);

        return response()->json(['success' => true]);
    }

    /**
     * Проверка маршрутов — одна и та же для станции и пункта посадки.
     *
     * @return array<string, mixed>
     */
    private function validateRoutes(Request $request): array
    {
        return $request->validate([
            'note' => ['nullable', 'string', 'max:255'],
            /*
             * Выключить автосборку: расписание места ведут полностью
             * вручную. Включённый признак ОЧИЩАЕТ собранную половину —
             * иначе на сайте осталось бы то, чему решили не верить.
             */
            'auto_disabled' => ['sometimes', 'boolean'],
            'routes' => ['present', 'array', 'max:200'],
            'routes.*.route_name' => ['required', 'string', 'max:128'],
            'routes.*.route_number' => ['nullable', 'string', 'max:32'],
            'routes.*.route_full_name' => ['nullable', 'string', 'max:255'],
            'routes.*.carrier' => ['nullable', 'string', 'max:128'],
            // Текст графика — как в legacy («Каждый день», «Ср, Пт»,
            // «Каждые 2 дня: 22.11.2026, 24.11.2026»).
            'routes.*.schedule' => ['required', 'string', 'max:255'],
            'routes.*.days' => ['sometimes', 'array', 'max:7'],
            'routes.*.days.*' => ['in:'.implode(',', self::DAYS)],
            'routes.*.fixed_dates' => ['sometimes', 'array', 'max:60'],
            'routes.*.fixed_dates.*' => ['string', 'max:20'],
            'routes.*.departures' => ['required', 'array', 'min:1', 'max:60'],
            /*
             * Время отправления — «ЧЧ:ММ». Ведущий ноль не требуем:
             * расписание переписывают с бумаги, и «9:15» там обычное
             * дело — приводим к «09:15» сами, а не отказываем.
             */
            'routes.*.departures.*.time' => ['required', 'regex:/^([01]?\d|2[0-3]):[0-5]\d$/'],
        ], [
            'routes.*.route_name.required' => 'У маршрута должно быть название.',
            'routes.*.schedule.required' => 'Укажите график: «Каждый день», «Ср, Пт» или перечень дат.',
            'routes.*.departures.required' => 'Добавьте хотя бы одно время отправления.',
            'routes.*.departures.*.time.regex' => 'Время отправления указывается как ЧЧ:ММ.',
        ]);
    }

    /**
     * Времена отправления по возрастанию и без повторов — в таком виде их
     * ждёт страница расписания (она печатает список как есть).
     *
     * @param  array<int, array<string, mixed>>  $departures
     * @return array<int, array<string, mixed>>
     */
    private function sortTimes(array $departures): array
    {
        $byTime = [];

        foreach ($departures as $departure) {
            $time = str_pad((string) $departure['time'], 5, '0', STR_PAD_LEFT);
            $byTime[$time] = ['time' => $time];
        }

        ksort($byTime);

        return array_values($byTime);
    }

    /**
     * Привести маршруты к формату, который ждут сайт и автоматическое
     * расписание: поля рейса (`napcod`, `raicecod`, `nomkat`) у ручного
     * расписания пустые — рейса в базе нет, и ссылки на тариф по времени
     * быть не должно.
     *
     * @param  array<int, array<string, mixed>>  $routes
     * @return array<int, array<string, mixed>>
     */
    private function normalize(array $routes): array
    {
        return array_values(array_map(fn (array $route) => [
            'route_name' => trim($route['route_name']),
            'trip_name' => trim($route['route_name']),
            'direction_name' => '',
            'napcod' => null,
            'route_number' => $route['route_number'] ?? null,
            'route_full_name' => $route['route_full_name'] ?? null,
            'carrier' => $route['carrier'] ?? null,
            'schedule' => trim($route['schedule']),
            'days' => array_values($route['days'] ?? []),
            'interval' => null,
            'fixed_dates' => array_values($route['fixed_dates'] ?? []),
            'next_dates' => [],
            'manual' => true,
            'departures' => array_values(array_map(fn (array $d) => [
                // «9:15» → «09:15»: страница сортирует времена строкой.
                'time' => str_pad($d['time'], 5, '0', STR_PAD_LEFT),
                'raicecod' => null,
                'nomkat' => null,
                'charter' => false,
            ], $this->sortTimes($route['departures']))),
        ], $routes));
    }
}
