<?php

namespace App\Services;

use App\Support\StationNameSorter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Куда можно уехать от пункта посадки: опрос станций и выдача сайту.
 *
 * Пара к {@see DestinationService} (он делает то же для станций) и с тем
 * же главным правилом: **неудачный опрос НЕ стирает прежний список** —
 * пустое «Куда» означает, что покупатель не может даже начать поиск.
 *
 * Отличие в том, что у пункта посадки станций несколько, и список «Куда»
 * собирается из всех: от Дросково в Ливны везёт рейс из базы Орла, а в
 * Орёл — рейс из базы Ливен. Поэтому у каждого пункта назначения
 * запомнено, ЧЬЯ база его продаёт, — иначе поиск рейсов не знал бы, куда
 * идти (docs/BOARDING_POINTS.md).
 */
class BoardingPointDestinationService
{
    public function __construct(
        private readonly SalesApiClient $salesApi,
        private readonly BoardingPointCatalog $points,
    ) {}

    /**
     * Список «Куда» для сайта: объединение по всем привязкам пункта.
     *
     * Один пункт назначения может продаваться несколькими станциями —
     * тогда он один в списке, а искать рейсы будем во всех (станции
     * хранятся в `stations`).
     *
     * @return array<string, mixed>
     */
    public function forPoint(string $slug): array
    {
        $point = $this->points->find($slug);

        if ($point === null) {
            return ['success' => false, 'error' => 'Пункт посадки не найден', 'data' => []];
        }

        $rows = DB::table('boarding_point_destinations')->where('slug', $slug)->get();

        $merged = [];
        $generatedAt = null;

        foreach ($point['stations'] as $binding) {
            $cached = $rows->first(
                fn ($row) => (int) $row->awtcod === $binding['awtcod']
                    && (string) $row->station_name === $binding['station_name'],
            );

            // Привязки ещё не опрашивали (добавили только что) — читаем
            // живьём: форма не должна остаться пустой из-за того, что мы
            // не успели обновиться.
            $destinations = $cached !== null
                ? $this->decode($cached->destinations)
                : $this->fetch($binding['awtcod'], $binding['station_name']);

            if ($cached?->generated_at !== null) {
                $generatedAt = max($generatedAt ?? '', (string) $cached->generated_at);
            }

            foreach ($destinations as $destination) {
                $name = (string) ($destination['name'] ?? '');

                if ($name === '' || in_array($name, $binding['excluded'], true)) {
                    continue;
                }

                $merged[$name] ??= [
                    'name' => $name,
                    'pre_sale_days' => (int) ($destination['pre_sale_days'] ?? 0),
                    'stations' => [],
                ];

                // Срок предпродажи по пункту — самый короткий из станций:
                // продать дальше, чем разрешила станция, всё равно нельзя.
                $merged[$name]['pre_sale_days'] = min(
                    $merged[$name]['pre_sale_days'] ?: PHP_INT_MAX,
                    (int) ($destination['pre_sale_days'] ?? 0) ?: PHP_INT_MAX,
                );

                $merged[$name]['stations'][] = [
                    'awtcod' => $binding['awtcod'],
                    'station_name' => $binding['station_name'],
                ];
            }
        }

        $list = array_values($merged);
        usort($list, fn (array $a, array $b) => StationNameSorter::compare($a['name'], $b['name']));

        return [
            'success' => true,
            'data' => $list,
            'generated_at' => $generatedAt,
        ];
    }

    /**
     * Станции, у которых от этого пункта продаётся билет до `$to`.
     *
     * Это и есть ответ на «куда уйдёт запрос»: по паре (пункт, куда)
     * выбираются только те базы, где такой тариф заведён.
     *
     * @return array<int, array{awtcod: int, station_name: string}>
     */
    public function routeFor(string $slug, string $to): array
    {
        foreach ($this->forPoint($slug)['data'] as $destination) {
            if ((string) $destination['name'] === $to) {
                return $destination['stations'];
            }
        }

        return [];
    }

    /**
     * Обновить списки всех пунктов посадки.
     *
     * @return array<int, array{slug: string, awtcod: int, station_name: string, destinations: ?int, error: ?string}>
     */
    public function syncAll(): array
    {
        $result = [];

        foreach ($this->points->all() as $point) {
            foreach ($point['stations'] as $binding) {
                $result[] = $this->sync($point['slug'], $binding['awtcod'], $binding['station_name']);
            }
        }

        return $result;
    }

    /** @return array{slug: string, awtcod: int, station_name: string, destinations: ?int, error: ?string} */
    public function sync(string $slug, int $awtcod, string $stationName): array
    {
        try {
            $destinations = $this->fetch($awtcod, $stationName);

            /*
             * Пустой список — НЕ повод затирать прежний: у остановки
             * тарифы заводят редко, и сорванный запрос легко принять за
             * «продажи больше нет». Если тарифы действительно убрали,
             * сотрудник выключит привязку в АИС.
             */
            if ($destinations === []) {
                throw new \RuntimeException('От остановки не вернулось ни одного пункта назначения');
            }

            DB::table('boarding_point_destinations')->updateOrInsert(
                ['slug' => $slug, 'awtcod' => $awtcod, 'station_name' => $stationName],
                [
                    'destinations' => json_encode($destinations, JSON_UNESCAPED_UNICODE),
                    'generated_at' => now(),
                    'sync_error' => null,
                    'updated_at' => now(),
                    'created_at' => now(),
                ],
            );

            return [
                'slug' => $slug,
                'awtcod' => $awtcod,
                'station_name' => $stationName,
                'destinations' => count($destinations),
                'error' => null,
            ];
        } catch (\Throwable $e) {
            Log::warning('Не удалось обновить пункты назначения от остановки', [
                'slug' => $slug,
                'awtcod' => $awtcod,
                'station_name' => $stationName,
                'error' => $e->getMessage(),
            ]);

            DB::table('boarding_point_destinations')
                ->where(['slug' => $slug, 'awtcod' => $awtcod, 'station_name' => $stationName])
                ->update(['sync_error' => mb_substr($e->getMessage(), 0, 500), 'updated_at' => now()]);

            return [
                'slug' => $slug,
                'awtcod' => $awtcod,
                'station_name' => $stationName,
                'destinations' => null,
                'error' => $e->getMessage(),
            ];
        }
    }

    /** @return array<int, array<string, mixed>> */
    private function fetch(int $awtcod, string $stationName): array
    {
        return $this->salesApi->destinationsFromStop($awtcod, $stationName)['data'] ?? [];
    }

    /** @return array<int, array<string, mixed>> */
    private function decode(mixed $raw): array
    {
        if (is_string($raw)) {
            $raw = json_decode($raw, true);
        }

        return is_array($raw) ? $raw : [];
    }
}
