<?php

namespace App\Support;

use Collator;

/**
 * Сортировка названий остановок по алфавиту.
 *
 * КОПИЯ `sales-api/app/Support/StationNameSorter.php` (и legacy
 * `ais/api/sort_help.php`): одно и то же правило нужно в трёх стеках, а
 * общего кода у приложений нет — в `shared/` лежат только конфиги.
 * Правила менять ОДНОВРЕМЕННО во всех копиях, иначе порядок «Куда» на
 * сайте разойдётся со списком станции (разбор — docs/STATIONS.md).
 *
 * Здесь список нужен затем, что «Куда» у пункта посадки собирается из
 * НЕСКОЛЬКИХ станций: каждая отдаёт свой отсортированный список, а
 * покупателю нужен один общий (docs/BOARDING_POINTS.md).
 *
 * KS_STATIONS отдаёт строки в порядке базы — с учётом регистра и знаков
 * препинания, поэтому аббревиатуры (АЗС, СХТ, ДРСУ-3) уходят в начало группы,
 * названия со строчной буквы (пов.Вепринец, м-н Москва, ул. Заводская) — в
 * конец списка, а «Б.Гать» встаёт раньше «Б. Жирово». Сортируем в PHP, а не в
 * SQL: сортировка Firebird и есть источник такого порядка.
 *
 * Ключ сортировки — название без «мусора»: нижний регистр, ё→е, всё кроме букв
 * и цифр считается разделителем, повторные и краевые пробелы убраны. Правило
 * то же, что в legacy: порядок в обоих стеках совпадает построчно (сверено на
 * боевых списках станций, включая 673 остановки Орла).
 */
final class StationNameSorter
{
    /** Ключ сортировки названия. */
    public static function key(string $name): string
    {
        $key = mb_strtolower($name);
        $key = str_replace('ё', 'е', $key);
        $key = preg_replace('/[^0-9a-zа-я]+/u', ' ', $key) ?? $key;

        return trim($key);
    }

    /** Сравнение двух названий по тому же правилу (для слияния списков). */
    public static function compare(string $a, string $b): int
    {
        $compare = self::comparator();
        $result = $compare(self::key($a), self::key($b));

        return $result !== 0 ? $result : $compare($a, $b);
    }

    /**
     * Сортировка списка по названию.
     *
     * @template TRow
     *
     * @param  iterable<TRow>  $rows
     * @param  callable(TRow): string  $name  как достать название из строки
     * @return list<TRow>
     */
    public static function sort(iterable $rows, callable $name): array
    {
        $prepared = [];
        $position = 0;
        foreach ($rows as $row) {
            $value = $name($row);
            $prepared[] = [
                'key' => self::key($value),
                'name' => $value,
                'position' => $position++,
                'row' => $row,
            ];
        }

        $compare = self::comparator();
        usort($prepared, function (array $a, array $b) use ($compare): int {
            $result = $compare($a['key'], $b['key']);
            if ($result !== 0) {
                return $result;
            }

            // Ключи совпали («Знак "Орел"» и «Знак"Орел"») — держим порядок
            // предсказуемым: по самому названию, затем по позиции в ответе базы.
            $result = $compare($a['name'], $b['name']);

            return $result !== 0 ? $result : $a['position'] <=> $b['position'];
        });

        return array_column($prepared, 'row');
    }

    /**
     * Сравнение строк: Collator русской локали, а без ext-intl — побайтово.
     * После нормализации второе даёт тот же порядок (в UTF-8 кириллица а..я
     * идёт подряд, цифры и латиница — до неё); так же сравнивает legacy на
     * PHP 5.6, где intl нет.
     *
     * @return callable(string, string): int
     */
    private static function comparator(): callable
    {
        if (! class_exists(Collator::class)) {
            return static fn (string $a, string $b): int => strcmp($a, $b) <=> 0;
        }

        $collator = new Collator('ru_RU');

        return static fn (string $a, string $b): int => $collator->compare($a, $b) ?: 0;
    }
}
