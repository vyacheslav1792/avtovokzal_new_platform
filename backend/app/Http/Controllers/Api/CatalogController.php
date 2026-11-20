<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Citizenship;
use App\Services\BoardingPointCatalog;
use App\Services\BoardingPointDestinationService;
use App\Services\DestinationService;
use App\Services\SalesApiException;
use App\Services\SalesApiClient;
use App\Support\VendorCode;
use App\Support\VendorText;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Справочные данные для фронта: станции, направления, рейсы, места. */
class CatalogController extends Controller
{
    public function __construct(
        private readonly SalesApiClient $salesApi,
        private readonly DestinationService $destinations,
        private readonly BoardingPointCatalog $boardingPoints,
        private readonly BoardingPointDestinationService $stopDestinations,
    ) {}

    public function stations(): JsonResponse
    {
        return response()->json($this->salesApi->stations());
    }

    /** Справочник гражданств (коды COUNTRIES_TABLE базы продаж, 185 = Россия). */
    public function citizenships(): JsonResponse
    {
        $list = cache()->remember('citizenships', 3600, fn () => Citizenship::query()
            ->where('active', true)
            ->orderBy('sort')
            ->orderBy('name')
            ->get(['code', 'name'])
            ->toArray());

        return response()->json(['success' => true, 'data' => $list]);
    }

    /**
     * Правила продажи, которые должна знать форма покупки, чтобы не давать
     * оформить заведомо отказной заказ (детский тариф без полного).
     * Значения задаются конфигом — см. `config/sale_rules.php`.
     */
    public function saleRules(): JsonResponse
    {
        return response()->json(['success' => true, 'data' => [
            'child_requires_adult' => (bool) config('sale_rules.child_requires_adult'),
            'max_children_per_adult' => (int) config('sale_rules.max_children_per_adult'),
            'child_requires_adult_message' => (string) config('sale_rules.child_requires_adult_message'),
        ]]);
    }

    /**
     * Пункты назначения станции (список «Куда»). Берутся из своей таблицы,
     * а не из базы продаж на каждый запрос: KS_STATIONS отдаёт две сотни
     * строк, а список меняется от случая к случаю. Обновляет команда
     * `destinations:sync` (ofelia, 07:00 и 10:00 МСК) и кнопка в АИС;
     * станции, которой в таблице ещё нет, список читается живьём.
     */
    public function destinations(int $awtcod): JsonResponse
    {
        return response()->json($this->destinations->forStation($awtcod));
    }

    /**
     * Пункты посадки — остановки на трассе, с которых сайт продаёт билеты
     * («Дросково» на рейсах Орёл–Ливны и Ливны–Орёл). Для формы поиска
     * они стоят в «Откуда» рядом со станциями (docs/BOARDING_POINTS.md).
     */
    public function boardingPoints(Request $request): JsonResponse
    {
        $data = $request->validate([
            'place' => ['sometimes', 'in:home,schedule'],
        ]);

        $points = collect($this->boardingPoints->forPlace($data['place'] ?? 'home'))
            ->map(fn (array $p) => [
                'slug' => $p['slug'],
                'name' => $p['name'],
                // Станции, которые обслуживают пункт: сайту они нужны лишь
                // для подсказки «билет оформляет такая-то автостанция».
                'stations' => collect($p['stations'])->pluck('awtcod')->unique()->values()->all(),
            ])
            ->values();

        return response()->json(['success' => true, 'data' => $points]);
    }

    /** Куда можно уехать от пункта посадки (объединение по его станциям). */
    public function boardingPointDestinations(string $slug): JsonResponse
    {
        $result = $this->stopDestinations->forPoint($slug);

        // Пункта нет — 404; пустой список у существующего пункта это 200:
        // тарифы от остановки станция могла ещё не завести.
        return response()->json($result, $result['success'] ? 200 : 404);
    }

    /**
     * Рейсы маршрута. Без date — «ближайшие» (перенос legacy blij=1),
     * all=1 — показывать и рейсы, недоступные для покупки через сайт
     * (транзитные, снятые с продажи, отменённые — как на legacy-странице
     * расписания; поиск билетов показывает только доступные).
     *
     * `from_point` вместо `awtcod` — поиск от ПУНКТА ПОСАДКИ. Тогда
     * станцию выбираем не мы и не покупатель, а тариф: у Дросково билет
     * до Ливен продаёт база Орла, а до Орла — база Ливен. Если нужный
     * пункт назначения продают несколько станций, спрашиваем все и
     * складываем рейсы в один список.
     */
    public function trips(Request $request): JsonResponse
    {
        $data = $request->validate([
            'awtcod' => ['required_without:from_point', ...VendorCode::rules(required: false)],
            'from_point' => ['required_without:awtcod', 'string', 'max:64'],
            /*
             * Пункт назначения уходит в базу станции параметром сравнения
             * (TRACES.STATNAME, WIN1251), поэтому проверяется и кодировка:
             * строка с символом, которого в WIN1251 нет, встречает там
             * ошибку -802 и превращается в «Server Error». Ловилось
             * 19.11.2026: обходчик открыл ссылку с названием остановки в
             * двойной кодировке UTF-8 (см. VendorText).
             */
            'to' => VendorText::rules(VendorText::MAX_STATION_NAME),
            'date' => ['nullable', 'date_format:Y-m-d'],
            'all' => ['sometimes', 'boolean'],
        ], [], ['to' => 'пункт назначения']);

        $forSale = ! filter_var($data['all'] ?? false, FILTER_VALIDATE_BOOLEAN);

        if (! empty($data['from_point'])) {
            return response()->json($this->tripsFromPoint(
                (string) $data['from_point'],
                $data['to'],
                $data['date'] ?? null,
                $forSale,
            ));
        }

        return response()->json($this->salesApi->trips(
            (int) $data['awtcod'],
            $data['to'],
            $data['date'] ?? null,
            $forSale,
        ));
    }

    public function places(Request $request): JsonResponse
    {
        $data = $request->validate([
            'awtcod' => VendorCode::rules(),
            'date' => ['required', 'date_format:Y-m-d'],
            'napcod' => VendorCode::rules(),
            'raicecod' => VendorCode::rules(),
            // Пункт назначения: с ним станция учитывает участок пути —
            // место, занятое только до середины трассы, для билета до неё
            // свободно (docs/BOARDING_POINTS.md).
            'to' => ['sometimes', ...VendorText::rules(VendorText::MAX_STATION_NAME, false)],
        ]);

        return response()->json($this->salesApi->places(
            (int) $data['awtcod'],
            $data['date'],
            (int) $data['napcod'],
            (int) $data['raicecod'],
            trim((string) ($data['to'] ?? '')),
        ));
    }

    /**
     * Поиск рейсов от пункта посадки: опрашиваем все станции, у которых
     * от этой остановки продаётся билет до нужного пункта.
     *
     * Недоступная станция не рушит выдачу: рейсы остальных покупатель
     * видит, а по недоступной уходит обычное предупреждение (правило
     * «недоступная станция — не сбой», docs/STATIONS.md). Если не
     * ответила ни одна — отдаём её отказ как есть, иначе покупатель
     * увидит «рейсов нет» там, где просто нет связи.
     *
     * @return array<string, mixed>
     */
    private function tripsFromPoint(string $slug, string $to, ?string $date, bool $forSale): array
    {
        $point = $this->boardingPoints->find($slug);

        // Несуществующий пункт посадки — 404 (прежде отдавалось 200 с
        // `success: false`, и сайт не мог отличить это от «рейсов нет»).
        if ($point === null) {
            throw new SalesApiException(
                'Пункт посадки не найден или больше не обслуживается',
                'boarding_point_not_found',
                404,
            );
        }

        $route = $this->stopDestinations->routeFor($slug, $to);

        if ($route === []) {
            return [
                'success' => true,
                'from_point' => $slug,
                'awtname' => $point['name'],
                'data' => [],
            ];
        }

        $trips = [];
        $errors = [];
        $warnings = [];

        foreach ($route as $binding) {
            try {
                $response = $this->salesApi->trips(
                    $binding['awtcod'],
                    $to,
                    $date,
                    $forSale,
                    $binding['station_name'],
                );
            } catch (SalesApiException $e) {
                $errors[] = $e;
                $warnings[] = $e->getMessage();

                continue;
            }

            foreach ($response['data'] ?? [] as $trip) {
                // Станция оформления у каждого рейса своя — без неё
                // покупка не найдёт ни рейс, ни места.
                $trip['awtcod'] = $binding['awtcod'];
                $trip['awtname'] = $response['awtname'] ?? null;
                $trip['from_point'] = $slug;
                $trip['from_name'] = $point['name'];
                $trip['from_station'] = $binding['station_name'];
                $trips[] = $trip;
            }
        }

        if ($trips === [] && $errors !== []) {
            throw $errors[0];
        }

        // Рейсы из разных баз складываются в один список — порядок задаём
        // по времени посадки, иначе он идёт блоками по станциям.
        usort($trips, fn (array $a, array $b) => [$a['date_start'], $a['departure_at'] ?? $a['time_start']]
            <=> [$b['date_start'], $b['departure_at'] ?? $b['time_start']]);

        return array_filter([
            'success' => true,
            'from_point' => $slug,
            'awtname' => $point['name'],
            'data' => $trips,
            'warnings' => $warnings ?: null,
        ], fn ($v) => $v !== null);
    }
}
