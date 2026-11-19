<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Citizenship;
use App\Services\DestinationService;
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
     * Рейсы маршрута. Без date — «ближайшие» (перенос legacy blij=1),
     * all=1 — показывать и рейсы, недоступные для покупки через сайт
     * (транзитные, снятые с продажи, отменённые — как на legacy-странице
     * расписания; поиск билетов показывает только доступные).
     */
    public function trips(Request $request): JsonResponse
    {
        $data = $request->validate([
            'awtcod' => VendorCode::rules(),
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

        return response()->json($this->salesApi->trips(
            (int) $data['awtcod'],
            $data['to'],
            $data['date'] ?? null,
            ! filter_var($data['all'] ?? false, FILTER_VALIDATE_BOOLEAN),
        ));
    }

    public function places(Request $request): JsonResponse
    {
        $data = $request->validate([
            'awtcod' => VendorCode::rules(),
            'date' => ['required', 'date_format:Y-m-d'],
            'napcod' => VendorCode::rules(),
            'raicecod' => VendorCode::rules(),
        ]);

        return response()->json($this->salesApi->places(
            (int) $data['awtcod'],
            $data['date'],
            (int) $data['napcod'],
            (int) $data['raicecod'],
        ));
    }
}
