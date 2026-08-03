<?php

namespace Database\Seeders;

use App\Models\MenuItem;
use App\Models\Page;
use Illuminate\Database\Seeder;

class PageSeeder extends Seeder
{
    /**
     * Первичное наполнение обычных страниц сайта и меню данными legacy
     * (avtovokzal57/url/<slug>.php + списки ссылок и switch с <title>
     * в index.php). Идемпотентен: если в таблицах уже что-то есть — не
     * трогаем, дальше страницы живут в админке.
     */
    public function run(): void
    {
        if (Page::query()->exists() || MenuItem::query()->exists()) {
            $this->command?->info('Страницы сайта уже заполнены — сид пропущен.');

            return;
        }

        $raw = json_decode(
            file_get_contents(database_path('data/pages.json')),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        foreach ($raw['pages'] as $page) {
            Page::create([
                'slug' => $page['slug'],
                'title' => $page['title'],
                'meta_title' => $page['meta_title'],
                'meta_description' => $page['meta_description'] ?: null,
                'content' => file_get_contents(database_path("data/pages/{$page['slug']}.html")),
                'is_active' => $page['is_active'] ?? true,
            ]);
        }

        foreach (['header', 'footer'] as $area) {
            foreach (array_values($raw['menu'][$area]) as $position => $item) {
                MenuItem::create([
                    'area' => $area,
                    'position' => $position,
                    'title' => $item['title'],
                    'url' => $item['url'],
                    'group' => $item['group'] ?? null,
                    'is_active' => $item['is_active'] ?? true,
                ]);
            }
        }
    }
}
