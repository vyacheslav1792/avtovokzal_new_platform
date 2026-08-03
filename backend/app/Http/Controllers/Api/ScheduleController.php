<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\SalesApiClient;
use App\Services\ScheduleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

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
                'awtcod' => (int) $schedule->awtcod,
                'name' => $schedule->station_name,
                'slug' => $schedule->slug,
                'routes' => $schedule->routes,
                'routes_count' => (int) $schedule->routes_count,
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
        $data = $request->validate([
            'awtcod' => ['required', 'integer'],
            'napcod' => ['required', 'integer'],
            'raicecod' => ['required', 'integer'],
            'variant' => ['sometimes', 'in:current,upcoming'],
        ]);

        $variant = $data['variant'] ?? 'current';
        // v2 — в кэш кладём ответ целиком (раньше только 'data'), поэтому
        // ключ другой: старые записи не подходят по форме.
        $key = "tariff:v2:{$data['awtcod']}:{$data['napcod']}:{$data['raicecod']}:{$variant}";

        $tariff = Cache::remember($key, now()->addMinutes(10), fn () => $this->salesApi->tariff(
            (int) $data['awtcod'],
            (int) $data['napcod'],
            (int) $data['raicecod'],
            $variant,
        ));

        return response()->json([
            'success' => true,
            // Название станции отправления нужно странице тарифа для ссылок
            // на рейсы до остановки.
            'awtname' => $tariff['awtname'] ?? null,
            'data' => $tariff['data'],
        ]);
    }
}
