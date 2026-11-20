<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Пункты посадки сайта — остановки на трассе рейсов, с которых продаются
 * билеты (docs/BOARDING_POINTS.md).
 *
 * Настройку правит сотрудник в АИС, поэтому читаем её ПРЯМЫМ запросом к
 * БД `ais` с коротким кэшем — так же, как sales-api читает справочник
 * станций, а backend — права сотрудников. Отдельный вызов по сети к
 * ais-api на каждое открытие формы поиска не нужен.
 *
 * Недоступная база АИС не должна гасить сайт: тогда пунктов посадки
 * просто нет, а продажа со станций работает как раньше.
 */
class BoardingPointCatalog
{
    private const CACHE_KEY = 'boarding-points:registry';

    private const CACHE_TTL = 60;

    /** @var array<string, array>|null */
    private ?array $memo = null;

    /**
     * Все действующие пункты посадки: slug => пункт с привязками.
     *
     * @return array<string, array{
     *     slug: string, name: string, show_on_home: bool, show_in_schedule: bool,
     *     inlist: int, stations: array<int, array{awtcod: int, station_name: string, excluded: array<int, string>}>
     * }>
     */
    public function all(): array
    {
        // Продажа от остановки — только у включённых пунктов; расписание
        // живёт по своему признаку, см. forSchedule().
        return array_filter($this->entries(), fn (array $p) => $p['is_active']);
    }

    /**
     * Все пункты посадки из справочника, включая выключенные.
     *
     * @return array<string, array>
     */
    public function entries(): array
    {
        if ($this->memo !== null) {
            return $this->memo;
        }

        try {
            $points = Cache::remember(self::CACHE_KEY, self::CACHE_TTL, fn () => $this->fromDatabase());
        } catch (Throwable) {
            $points = [];
        }

        return $this->memo = $points;
    }

    public function find(string $slug): ?array
    {
        return $this->all()[$slug] ?? null;
    }

    /**
     * Пункты для РАЗДЕЛА РАСПИСАНИЯ — те, у кого стоит `show_in_schedule`,
     * независимо от `is_active`.
     *
     * Это разные вещи: `is_active` открывает ПРОДАЖУ от остановки (пункт
     * появляется в «Откуда»), а расписание проходящих автобусов — справка,
     * которая нужна людям и до открытия продажи. Именно так пункты и
     * заводят: сперва расписание, потом продажа.
     *
     * @return array<string, array>
     */
    public function forSchedule(): array
    {
        return array_filter($this->entries(), fn (array $p) => $p['show_in_schedule']);
    }

    /** Пункты для формы поиска на главной / для расписания. */
    public function forPlace(string $place = 'home'): array
    {
        /*
         * РАЗДЕЛ РАСПИСАНИЯ И ПРОДАЖА — РАЗНЫЕ ПРИЗНАКИ.
         *
         * В расписание пункт попадает по `show_in_schedule`, даже если
         * продажа от него выключена: у закрытой станции своей базы нет, а
         * автобусы через неё идут, и расписание показать надо (так было и
         * в legacy — для таких мест лежал статический HTML). Иначе
         * добавить остановку в расписание можно было бы только открыв
         * продажу, то есть и выставив её на главную.
         */
        if ($place === 'schedule') {
            return array_values($this->forSchedule());
        }

        return array_values(array_filter($this->all(), fn (array $p) => $p['show_on_home']));
    }

    public function forget(): void
    {
        $this->memo = null;
        Cache::forget(self::CACHE_KEY);
    }

    /** @return array<string, array> */
    private function fromDatabase(): array
    {
        $rows = DB::connection('ais')
            ->table('boarding_points')
            ->leftJoin('boarding_point_stations', function ($join) {
                $join->on('boarding_point_stations.boarding_point_id', '=', 'boarding_points.id')
                    ->where('boarding_point_stations.is_active', true);
            })
            ->orderBy('boarding_points.inlist')
            ->orderBy('boarding_points.name')
            ->orderBy('boarding_point_stations.inlist')
            ->get([
                'boarding_points.slug', 'boarding_points.name', 'boarding_points.inlist',
                'boarding_points.is_active',
                'boarding_points.show_on_home', 'boarding_points.show_in_schedule',
                'boarding_point_stations.awtcod', 'boarding_point_stations.station_name',
                'boarding_point_stations.excluded_destinations',
            ]);

        $points = [];

        foreach ($rows as $row) {
            $slug = (string) $row->slug;

            $points[$slug] ??= [
                'slug' => $slug,
                'name' => (string) $row->name,
                'is_active' => (bool) $row->is_active,
                'show_on_home' => (bool) $row->show_on_home,
                'show_in_schedule' => (bool) $row->show_in_schedule,
                'inlist' => (int) $row->inlist,
                'stations' => [],
            ];

            // Пункт без привязок остаётся в списке: сотрудник мог создать
            // его заранее. Пунктов назначения у него просто не будет.
            if ($row->awtcod === null) {
                continue;
            }

            $points[$slug]['stations'][] = [
                'awtcod' => (int) $row->awtcod,
                'station_name' => (string) $row->station_name,
                'excluded' => $this->excluded($row->excluded_destinations),
            ];
        }

        return $points;
    }

    /** @return array<int, string> */
    private function excluded(mixed $raw): array
    {
        if (is_string($raw)) {
            $raw = json_decode($raw, true);
        }

        return is_array($raw) ? array_values(array_filter($raw, 'is_string')) : [];
    }
}
