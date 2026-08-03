<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Регулярное расписание станции. В legacy лежало HTML-файлами
 * (url/raspisanie/*.html) — здесь строка на станцию с данными в jsonb.
 */
class StationSchedule extends Model
{
    protected $guarded = [];

    protected $casts = [
        'routes' => 'array',
        'generated_at' => 'datetime',
        'synced_at' => 'datetime',
    ];

    /** Транслит названия станции — как в именах legacy-файлов расписания. */
    public static function slug(string $name): string
    {
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
