<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Citizenship;
use App\Services\SalesApiClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Справочные данные для фронта: станции, направления, рейсы, места. */
class CatalogController extends Controller
{
    public function __construct(private readonly SalesApiClient $salesApi) {}

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

    public function destinations(int $awtcod): JsonResponse
    {
        return response()->json($this->salesApi->destinations($awtcod));
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
            'awtcod' => ['required', 'integer'],
            'to' => ['required', 'string', 'max:255'],
            'date' => ['nullable', 'date_format:Y-m-d'],
            'all' => ['sometimes', 'boolean'],
        ]);

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
            'awtcod' => ['required', 'integer'],
            'date' => ['required', 'date_format:Y-m-d'],
            'napcod' => ['required', 'integer'],
            'raicecod' => ['required', 'integer'],
        ]);

        return response()->json($this->salesApi->places(
            (int) $data['awtcod'],
            $data['date'],
            (int) $data['napcod'],
            (int) $data['raicecod'],
        ));
    }
}
