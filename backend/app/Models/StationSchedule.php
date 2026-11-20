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
        'awtcod' => 'int',
        'routes' => 'array',
        /*
         * Маршруты, добавленные вручную в АИС, — отдельно от собранных
         * из баз: синк обновляет только `routes`, редактор — только
         * `manual_routes`, а сайт показывает их вместе.
         */
        'manual_routes' => 'array',
        // Автосборка выключена — синк это место не трогает, на сайте
        // остаются только маршруты, добавленные вручную.
        'auto_disabled' => 'bool',
        /*
         * ПРЕЖНЯЯ схема: «всё расписание ручное» (либо из базы, либо
         * руками). Половины разъехались по разным полям, признак сброшен
         * миграцией `2026_11_21_000004` и больше ничего не решает —
         * автосборку выключает `auto_disabled`. Оставлен, чтобы откат
         * миграции нашёл колонку (docs/FRONTEND.md).
         */
        'is_manual' => 'bool',
        'generated_at' => 'datetime',
        'synced_at' => 'datetime',
    ];

    /**
     * Все маршруты, которые видит посетитель: собранные из баз станций и
     * добавленные вручную, по времени первого отправления.
     *
     * @return array<int, array<string, mixed>>
     */
    public function allRoutes(): array
    {
        $routes = array_merge($this->routes ?? [], $this->manual_routes ?? []);

        usort($routes, fn (array $a, array $b) => strcmp(
            $a['departures'][0]['time'] ?? '', $b['departures'][0]['time'] ?? '',
        ));

        return $routes;
    }

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
