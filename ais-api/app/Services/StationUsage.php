<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Какие данные уже накопились по станции.
 *
 * Нужно ровно для одного: станцию, по которой есть следы работы, удалять
 * нельзя — её выключают (`is_active`). Удаление задумано для станции,
 * заведённой по ошибке или так и не включённой в работу, а не как способ
 * убрать её из списков: заказы сайта и собранные продажи остались бы
 * строками с кодом, которого больше нет ни в одном справочнике.
 *
 * Производный кэш (расписание, пункты назначения) тоже считается данными:
 * стереть его мы не можем (БД сайта у нас только на чтение), а строка с
 * кодом удалённой станции осталась бы в ней сиротой.
 */
class StationUsage
{
    /**
     * Что есть по каждому коду станции — БЕЗ подсчёта строк.
     *
     * Списку станций нужно только «есть или нет», а считать строки дорого:
     * `count(*) group by` по заказам сайта (их миллион) и по собранным
     * продажам занимал полсекунды на открытие страницы. Здесь на таблицу
     * идёт один запрос вида «для каких из этих кодов существует хоть одна
     * строка» — полусоединение по индексу останавливается на первой
     * найденной строке.
     *
     * @param  array<int, int>  $codes
     * @return array<int, array<int, string>> код станции → перечень «чего»
     */
    public function map(array $codes): array
    {
        $codes = array_values(array_unique(array_map('intval', $codes)));

        if ($codes === []) {
            return [];
        }

        $usage = [];

        foreach ($this->sources() as $title => [$connection, $table, $column]) {
            foreach ($this->existing($connection, $table, $column, $codes) as $code) {
                $usage[$code][] = $title;
            }
        }

        return $usage;
    }

    /**
     * Что и сколько найдено по ОДНОЙ станции: строки считаются, потому что
     * этим числом объясняется отказ в удалении — «заказы сайта: 12».
     *
     * @return array<string, int> «что» => «сколько»
     */
    public function forStation(int $code): array
    {
        $found = [];

        foreach ($this->sources() as $title => [$connection, $table, $column]) {
            $count = DB::connection($connection)->table($table)->where($column, $code)->count();

            if ($count > 0) {
                $found[$title] = $count;
            }
        }

        return $found;
    }

    /**
     * Человеческое перечисление найденного — его показывают сотруднику.
     * Принимает и перечень из `map()`, и счётчики из `forStation()`.
     */
    public function describe(array $found): string
    {
        $parts = [];

        foreach ($found as $title => $count) {
            $parts[] = is_int($title) ? (string) $count : $title.': '.$count;
        }

        return implode(', ', $parts);
    }

    /**
     * Где искать следы станции.
     *
     * @return array<string, array{0: ?string, 1: string, 2: string}>
     */
    private function sources(): array
    {
        return [
            // Покупки через сайт — главное, что нельзя осиротить.
            'заказы сайта' => ['site', 'orders', 'awtcod'],
            'собранные продажи' => [null, 'station_sales', 'source_station'],
            'задания на сбор продаж' => [null, 'station_sales_sync_runs', 'station_code'],
            'отметки о собранных днях' => [null, 'station_sales_sync_days', 'station_code'],
            'состояние сбора продаж' => [null, 'station_sales_sync_states', 'station_code'],
            // Производный кэш сайта: восстанавливается синхронизацией, но
            // удалить его отсюда нечем — БД сайта только на чтение.
            'расписание на сайте' => ['site', 'station_schedules', 'awtcod'],
            'пункты назначения на сайте' => ['site', 'station_destinations', 'awtcod'],
        ];
    }

    /**
     * Для каких из переданных кодов в таблице есть хоть одна строка.
     *
     * Имя таблицы и колонки берутся из `sources()` — это наш код, а не
     * то, что пришло снаружи; сами коды уходят параметрами.
     *
     * @return array<int, int>
     */
    private function existing(?string $connection, string $table, string $column, array $codes): array
    {
        // Приведение к int обязательно: параметр PDO уходит в VALUES как
        // text, и Postgres отказывается сравнивать его с кодом станции
        // («operator does not exist: integer = text»).
        $values = implode(', ', array_fill(0, count($codes), '(?::int)'));

        $rows = DB::connection($connection)->select(
            'select v.code from (values '.$values.') as v(code)'
            .' where exists (select 1 from '.$table.' as t where t.'.$column.' = v.code)',
            $codes,
        );

        return array_map(fn ($row) => (int) $row->code, $rows);
    }
}
