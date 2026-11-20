<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\BoardingPointCatalog;
use App\Services\SalesApiClient;
use App\Services\SalesApiException;
use App\Services\ScheduleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use App\Support\VendorCode;
use App\Support\VendorText;

/**
 * Регулярное расписание для сайта (перенос страницы legacy
 * /raspisanie-avtobusov). Данные берутся из БД — в API продаж на каждый
 * заход не ходим, расписание обновляет команда `schedule:sync`.
 */
class ScheduleController extends Controller
{
    public function __construct(
        private readonly ScheduleService $schedules,
        private readonly SalesApiClient $salesApi,
        private readonly BoardingPointCatalog $boardingPoints,
    ) {}

    /** Станции, для которых есть расписание. */
    public function index(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $this->schedules->stations(),
        ]);
    }

    /** Расписание станции: `{station}` — awtcod, название или slug. */
    public function show(string $station): JsonResponse
    {
        $schedule = $this->schedules->find($station);

        if (! $schedule) {
            return response()->json([
                'success' => false,
                'error' => 'Расписание для этой станции пока не опубликовано',
                'error_code' => 'schedule_not_found',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'awtcod' => $schedule->awtcod === null ? null : (int) $schedule->awtcod,
                // Пункт посадки вместо станции: у него нет своей базы и
                // билеты от него оформляют соседние станции.
                'boarding_point' => $schedule->boarding_point_slug,
                'name' => $schedule->station_name,
                'slug' => $schedule->slug,
                'routes' => $schedule->routes,
                'routes_count' => (int) $schedule->routes_count,
                /*
                 * Расписание заведено вручную (станция закрыта, базы
                 * продаж нет): страница пишет, что рейсы показаны для
                 * справки, и не ведёт со времени на тариф — рейса в базе
                 * нет (docs/FRONTEND.md).
                 */
                'is_manual' => (bool) $schedule->is_manual,
                // «Актуально на» — как в шапке legacy-таблицы.
                'generated_at' => $schedule->generated_at
                    ?->timezone(config('app.timezone'))->format('d.m.Y'),
            ],
        ]);
    }

    /**
     * Тариф рейса по остановкам (страница /tarif, перенос legacy).
     *
     * Тарифную категорию (`nomkat`) выбирает API продаж: у рейса их бывает
     * две — действующая и новая, вступающая в силу с даты смены. Клиент
     * просит только вариант: `current` (по умолчанию) или `upcoming`.
     * Кэш короткий: тарифы меняются редко, но страница не должна показывать
     * вчерашние цифры после правки на станции.
     */
    public function tariff(Request $request): JsonResponse
    {
        /*
         * min:1 — ноль не идентификатор. Обрезанные ссылки на тариф
         * (`/tarif?from=5701&napcod=35` без рейса) превращались во фронте
         * в `raicecod=0`, уходили в API продаж и возвращались ошибкой
         * «The raicecod field is required» — по сотне записей ERROR в
         * сутки. Теперь такой запрос отсекается здесь и в журнал не
         * попадает (ошибки проверки Laravel не логируются).
         */
        $data = $request->validate([
            'awtcod' => VendorCode::rules(),
            'napcod' => VendorCode::rules(),
            'raicecod' => VendorCode::rules(),
            'variant' => ['sometimes', 'in:current,upcoming'],
            /*
             * Тариф ОТ ОСТАНОВКИ на трассе (docs/BOARDING_POINTS.md).
             * Два способа задать её, и оба нужны:
             *  - `from_point` — пункт посадки сайта (его ключ): название
             *    остановки в базе станции берём из справочника, подменить
             *    параметром нельзя, а в заголовке будет имя для
             *    покупателя («Дросково»);
             *  - `from_stop` — прямое название остановки: им пользуются
             *    АИС и агенты, у которых пункта посадки нет вовсе.
             */
            'from_point' => ['sometimes', 'nullable', 'string', 'max:64', 'regex:/^[a-z0-9][a-z0-9-]*$/'],
            'from_stop' => ['sometimes', ...VendorText::rules(VendorText::MAX_STATION_NAME, false)],
        ]);

        $variant = $data['variant'] ?? 'current';
        $from = $this->tariffStop($data);

        // v2 — в кэш кладём ответ целиком (раньше только 'data'), поэтому
        // ключ другой: старые записи не подходят по форме. Остановка
        // отправления входит в ключ: цены от неё другие.
        $key = "tariff:v2:{$data['awtcod']}:{$data['napcod']}:{$data['raicecod']}:{$variant}"
            .($from['stop'] !== '' ? ':'.md5($from['stop']) : '');

        $tariff = Cache::remember($key, now()->addMinutes(10), fn () => $this->salesApi->tariff(
            (int) $data['awtcod'],
            (int) $data['napcod'],
            (int) $data['raicecod'],
            $variant,
            $from['stop'],
        ));

        return response()->json([
            'success' => true,
            // Название станции отправления нужно странице тарифа для ссылок
            // на рейсы до остановки.
            'awtname' => $tariff['awtname'] ?? null,
            // Пункт посадки: его имя показывает страница вместо станции.
            'from_point' => $from['slug'],
            'from_name' => $from['name'],
            'data' => $tariff['data'],
        ]);
    }

    /**
     * Остановка отправления для карточки тарифа.
     *
     * У пункта посадки берём название остановки из справочника (а не из
     * запроса) и проверяем, что эту станцию он действительно
     * обслуживает: иначе страница показывала бы цены от остановки,
     * которой на рейсах этой станции нет.
     *
     * @param  array<string, mixed>  $data
     * @return array{slug: ?string, name: ?string, stop: string}
     */
    private function tariffStop(array $data): array
    {
        $slug = trim((string) ($data['from_point'] ?? ''));

        if ($slug === '') {
            return ['slug' => null, 'name' => null, 'stop' => trim((string) ($data['from_stop'] ?? ''))];
        }

        $point = $this->boardingPoints->find($slug);

        /*
         * Несуществующий (или выключенный) пункт посадки — это 404, а не
         * ошибка поля: по таким ссылкам ходят поисковики и люди с
         * сохранёнными адресами, и «страница есть, но параметр неверный»
         * тут неправда — такой страницы нет.
         */
        if ($point === null) {
            throw new SalesApiException(
                'Пункт посадки не найден или больше не обслуживается',
                'boarding_point_not_found',
                404,
            );
        }

        foreach ($point['stations'] as $binding) {
            if ((int) $binding['awtcod'] === (int) $data['awtcod']) {
                return [
                    'slug' => $point['slug'],
                    'name' => $point['name'],
                    'stop' => $binding['station_name'],
                ];
            }
        }

        // Пункт есть, но эта станция его рейсы не ведёт: сочетание в
        // адресе невозможное, страницы с таким тарифом не существует.
        throw new SalesApiException(
            'От этого пункта посадки выбранная автостанция рейсы не ведёт',
            'boarding_point_not_found',
            404,
        );
    }
}
