<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Регулярное расписание станции для сайта.
 *
 * Таблица живёт в БД САЙТА (соединение `site`): её наполняет backend
 * командой `schedule:sync` из баз станций, а сайт по ней рисует раздел
 * «Расписание автобусов». АИС обращается к ней затем, чтобы ВРУЧНУЮ
 * вести расписание станций, у которых базы продаж больше нет: в legacy
 * для них лежал готовый HTML-файл (`url/raspisanie/<slug>.html`), и
 * правили его текстовым редактором.
 *
 * `is_manual` защищает ручную работу от перезаписи: синк такие станции
 * пропускает.
 */
class StationSchedule extends Model
{
    protected $connection = 'site';

    protected $table = 'station_schedules';

    protected $guarded = [];

    protected $casts = [
        'awtcod' => 'int',
        'routes' => 'array',
        'routes_count' => 'int',
        'is_manual' => 'bool',
        'generated_at' => 'datetime',
        'synced_at' => 'datetime',
    ];

    /**
     * Ключ станции в адресе страницы расписания (`?from=<slug>`).
     *
     * Правило переноса слов повторяет backend (`StationSchedule::slug`) —
     * иначе ручное расписание открывалось бы по другому адресу, чем
     * автоматическое.
     */
    public static function slug(string $name): string
    {
        /*
         * Таблица переноса повторяет backend (`App\Models\StationSchedule`)
         * БУКВА В БУКВУ, включая апостроф на месте мягкого и твёрдого
         * знака: именно он превращает «Верховье КП» в `verhov-e-kp` — тот
         * же адрес, что был у статических файлов legacy
         * (`url/raspisanie/verhov-e-kp.html`). Любое расхождение здесь
         * означает, что ручное расписание откроется по другому адресу,
         * чем автоматическое, и прежние ссылки сломаются.
         */
        $map = [
            'а' => 'a', 'б' => 'b', 'в' => 'v', 'г' => 'g', 'д' => 'd', 'е' => 'e',
            'ё' => 'e', 'ж' => 'zh', 'з' => 'z', 'и' => 'i', 'й' => 'y', 'к' => 'k',
            'л' => 'l', 'м' => 'm', 'н' => 'n', 'о' => 'o', 'п' => 'p', 'р' => 'r',
            'с' => 's', 'т' => 't', 'у' => 'u', 'ф' => 'f', 'х' => 'h', 'ц' => 'c',
            'ч' => 'ch', 'ш' => 'sh', 'щ' => 'sch', 'ь' => "'", 'ы' => 'y', 'ъ' => "'",
            'э' => 'e', 'ю' => 'yu', 'я' => 'ya',
        ];

        $slug = strtr(mb_strtolower($name), $map);
        $slug = preg_replace('~[^-a-z0-9_]+~u', '-', $slug);

        return trim((string) $slug, '-');
    }
}
