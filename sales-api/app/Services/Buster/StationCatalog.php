<?php

namespace App\Services\Buster;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Справочник станций: таблица `stations` в БД АИС (её правит сотрудник в
 * АИС — раньше это был файл shared/stations.php, а в legacy массив $AWT
 * прямо в коде, причём в трёх местах).
 *
 * Читаем ПРЯМЫМ запросом к БД `ais`, а не по сети у ais-api: лишний вызов
 * с кэшем на каждый запрос продаж не нужен, а соединение к этой базе у
 * сервисов и так есть (так же поступает backend с правами сотрудников).
 *
 * Пока справочник пуст или база АИС недоступна — работает прежний файл:
 * продажа не должна остановиться из-за того, что справочник переехал.
 *
 * Кэш 30 секунд: переключение варианта подключения из АИС доезжает почти
 * сразу, но каждый запрос не ходит в базу за теми же двумя десятками строк.
 */
class StationCatalog
{
    private const CACHE_KEY = 'stations:registry';

    private const CACHE_TTL = 30;

    /** @var array<string, array>|null */
    private ?array $memo = null;

    /**
     * Станции в формате прежнего конфига: код => поля (включая реквизиты
     * действующего варианта подключения). Отключённых станций здесь нет
     * вовсе — для продаж такой станции не существует.
     *
     * @return array<string, array>
     */
    public function entries(): array
    {
        if ($this->memo !== null) {
            return $this->memo;
        }

        try {
            $entries = Cache::remember(self::CACHE_KEY, self::CACHE_TTL, fn () => $this->fromDatabase());
        } catch (Throwable) {
            $entries = [];
        }

        return $this->memo = $entries ?: (array) config('stations.list', []);
    }

    /** @return array<string, array> */
    private function fromDatabase(): array
    {
        $rows = DB::connection('ais')
            ->table('stations')
            // Действующий вариант подключения — тот, чей slot совпадает с
            // active_profile станции.
            ->join('station_profiles', function ($join) {
                $join->on('station_profiles.station_awtcod', '=', 'stations.awtcod')
                    ->on('station_profiles.slot', '=', 'stations.active_profile');
            })
            ->where('stations.is_active', true)
            ->orderBy('stations.inlist')
            ->get([
                'stations.awtcod', 'stations.name', 'stations.buh', 'stations.buh_st',
                'stations.buh_ksr', 'stations.stationkey', 'stations.inlist',
                'stations.sales_enabled', 'stations.refunds_enabled',
                'station_profiles.db_host', 'station_profiles.db_port',
                'station_profiles.db_name', 'station_profiles.db_user',
                'station_profiles.db_password',
            ]);

        $entries = [];

        foreach ($rows as $row) {
            $entries[(string) $row->awtcod] = [
                'name' => $row->name,
                'db_host' => $row->db_host,
                'db_port' => (int) $row->db_port,
                'db_name' => $row->db_name,
                'db_user' => $row->db_user,
                'db_password' => $row->db_password,
                'buh' => $row->buh,
                'buh_st' => $row->buh_st,
                'buh_ksr' => $row->buh_ksr,
                'stationkey' => $row->stationkey,
                'inlist' => (int) $row->inlist,
                'sales_enabled' => (bool) $row->sales_enabled,
                'refunds_enabled' => (bool) $row->refunds_enabled,
            ];
        }

        return $entries;
    }
}
