<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\BoardingPointDestinationService;
use App\Services\DestinationService;
use App\Services\SalesApiClient;
use App\Services\ScheduleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;
use App\Support\VendorCode;

/**
 * Немедленное обновление данных, которые сайт держит у себя: регулярного
 * расписания и пунктов назначения станций.
 *
 * Зачем кнопка: и то и другое обновляется по расписанию (расписание —
 * ночью, пункты назначения — в 07:00 и 10:00 МСК), а правка графика или
 * новая остановка на станции нужна на сайте сразу. В legacy для
 * расписания такая кнопка была (`getrasp.php?action=raspisanie`), и с
 * переездом на новый стек она потерялась.
 *
 * Права сотрудника АИС: `data-sync.view` на состояние, `data-sync.run` на
 * запуск. Кода подтверждения не требует: операция повторяемая и ничего не
 * ломает — при неудаче прежние данные остаются на месте.
 */
class AdminDataSyncController extends Controller
{
    public function __construct(
        private readonly ScheduleService $schedules,
        private readonly DestinationService $destinations,
        private readonly SalesApiClient $salesApi,
        private readonly BoardingPointDestinationService $stopDestinations,
    ) {}

    /** Состояние обоих кэшей плюс список станций для выбора одной. */
    public function index(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => [
                'schedules' => $this->schedules->status(),
                'destinations' => $this->destinations->status(),
                'stations' => $this->stations(),
            ],
        ]);
    }

    /** Обновить расписание: всё или одну станцию (`awtcod`). */
    public function syncSchedules(Request $request): JsonResponse
    {
        $awtcod = $this->awtcod($request);

        $rows = $awtcod === null
            ? $this->schedules->syncAll()
            : [$this->schedules->sync($awtcod, $this->stationName($awtcod))];

        return $this->result($rows, 'routes');
    }

    /** Обновить пункты назначения: всё или одну станцию (`awtcod`). */
    public function syncDestinations(Request $request): JsonResponse
    {
        $awtcod = $this->awtcod($request);

        $rows = $awtcod === null
            ? $this->destinations->syncAll()
            : [$this->destinations->sync($awtcod)];

        /*
         * Вместе со станциями обновляем и пункты посадки (остановки на
         * трассе, с которых продаёт сайт): их список «Куда» собирается из
         * тех же станционных баз, и держать для него отдельную кнопку
         * значило бы забыть её нажать (docs/BOARDING_POINTS.md).
         */
        $stopRows = $this->boardingPointRows($awtcod);

        return $this->result($rows, 'destinations', $stopRows);
    }

    /**
     * Обновление пунктов посадки — отдельной строкой в итоге.
     *
     * @return array<int, array<string, mixed>>
     */
    private function boardingPointRows(?int $awtcod): array
    {
        $rows = $this->stopDestinations->syncAll();

        if ($awtcod !== null) {
            $rows = array_values(array_filter($rows, fn (array $r) => $r['awtcod'] === $awtcod));
        }

        return array_map(fn (array $r) => [
            'slug' => $r['slug'],
            'awtcod' => $r['awtcod'],
            'station_name' => $r['station_name'],
            'count' => $r['destinations'],
            'error' => $r['error'],
        ], $rows);
    }

    private function awtcod(Request $request): ?int
    {
        $data = $request->validate([
            'awtcod' => VendorCode::rules(required: false),
        ]);

        return $data['awtcod'] ?? null;
    }

    /**
     * Итог прогона в одном виде для обоих обновлений: что обновилось, где
     * ошибка и каково состояние после.
     *
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function result(array $rows, string $countKey, array $boardingPoints = []): JsonResponse
    {
        $failed = array_values(array_filter($rows, fn (array $r) => $r['error'] !== null));

        return response()->json([
            'success' => true,
            'data' => array_filter([
                'total' => count($rows),
                'failed' => count($failed),
                'rows' => array_map(fn (array $r) => [
                    'awtcod' => $r['awtcod'],
                    'name' => $r['name'],
                    'count' => $r[$countKey],
                    'error' => $r['error'],
                ], $rows),
                // Пункты посадки: пусто, когда их не заводили вовсе.
                'boarding_points' => $boardingPoints ?: null,
                'schedules' => $this->schedules->status(),
                'destinations' => $this->destinations->status(),
            ], fn ($v) => $v !== null),
        ]);
    }

    /** @return array<int, array<string, mixed>> */
    private function stations(): array
    {
        try {
            return array_map(
                fn (array $s) => ['awtcod' => (int) $s['awtcod'], 'name' => (string) $s['name']],
                $this->salesApi->stations()['data'],
            );
        } catch (Throwable) {
            // База продаж недоступна — страница всё равно должна открыться
            // и показать, что лежит в кэше.
            return [];
        }
    }

    private function stationName(int $awtcod): string
    {
        foreach ($this->stations() as $station) {
            if ($station['awtcod'] === $awtcod) {
                return $station['name'];
            }
        }

        return (string) $awtcod;
    }
}
