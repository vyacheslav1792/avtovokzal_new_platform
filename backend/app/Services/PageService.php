<?php

namespace App\Services;

use App\Models\MenuItem;
use App\Models\Page;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Обычные страницы сайта и меню (шапка/подвал) — перенос legacy
 * avtovokzal57: каждая страница была файлом url/<slug>.php, её <title>
 * и description — веткой switch в index.php, а списки меню лежали прямо
 * в вёрстке index.php. Всё это теперь данные и правится в админке АИС.
 */
class PageService
{
    private const CACHE_PAGES = 'pages:public';

    private const CACHE_MENU = 'pages:menu';

    private const CACHE_TTL = 300;

    /**
     * Все включённые страницы для сайта: slug → содержимое и мета.
     * Страниц десяток, читаются на каждом заходе — держим одной записью
     * кэша (драйвер database, теги кэша там недоступны).
     *
     * @return array<string, array<string, mixed>>
     */
    public function publicPages(): array
    {
        return Cache::remember(self::CACHE_PAGES, self::CACHE_TTL, function () {
            return Page::query()
                ->where('is_active', true)
                ->orderBy('slug')
                ->get()
                ->mapWithKeys(fn (Page $page) => [$page->slug => [
                    'slug' => $page->slug,
                    'title' => $page->title,
                    'meta_title' => $page->meta_title,
                    'meta_description' => $page->meta_description,
                    'content' => $page->content,
                    'updated_at' => optional($page->updated_at)->toIso8601String(),
                ]])
                // В кэш кладём массивы: Eloquent Collection в database-кэше
                // десериализуется в incomplete class.
                ->all();
        });
    }

    /** Страница сайта по адресу; null — если её нет или она выключена. */
    public function publicPage(string $slug): ?array
    {
        return $this->publicPages()[$slug] ?? null;
    }

    /**
     * Меню сайта для фронта. Шапка отдаётся деревом: пункт без group —
     * обычная ссылка, пункты с одинаковым group собираются в выпадающий
     * раздел (в legacy это «Пассажирам»), место раздела определяется
     * позицией его первого пункта. Подвал — плоский список.
     *
     * @return array{header: array<int, array<string, mixed>>, footer: array<int, array<string, mixed>>}
     */
    public function menu(): array
    {
        return Cache::remember(self::CACHE_MENU, self::CACHE_TTL, function () {
            $items = MenuItem::query()
                ->where('is_active', true)
                ->orderBy('position')->orderBy('id')
                ->get();

            $header = [];
            $groups = [];   // название раздела → индекс в $header

            foreach ($items->where('area', 'header') as $item) {
                $group = trim((string) $item->group);

                if ($group === '') {
                    $header[] = ['title' => $item->title, 'url' => $item->url];

                    continue;
                }

                if (! isset($groups[$group])) {
                    $groups[$group] = count($header);
                    $header[] = ['title' => $group, 'children' => []];
                }

                $header[$groups[$group]]['children'][] = [
                    'title' => $item->title,
                    'url' => $item->url,
                ];
            }

            $footer = $items->where('area', 'footer')
                ->map(fn (MenuItem $item) => ['title' => $item->title, 'url' => $item->url])
                ->values()
                ->all();

            return ['header' => $header, 'footer' => $footer];
        });
    }

    /** Список страниц для админки (без содержимого — оно объёмное). */
    public function adminList(): array
    {
        return Page::query()
            ->orderBy('title')
            ->get()
            ->map(fn (Page $page) => [
                'id' => $page->id,
                'slug' => $page->slug,
                'title' => $page->title,
                'meta_title' => $page->meta_title,
                'meta_description' => $page->meta_description,
                'is_active' => $page->is_active,
                'updated_at' => optional($page->updated_at)->toIso8601String(),
            ])
            ->all();
    }

    /** Страница целиком для админки (включая выключенные). */
    public function adminPage(Page $page): array
    {
        return [
            'id' => $page->id,
            'slug' => $page->slug,
            'title' => $page->title,
            'meta_title' => $page->meta_title,
            'meta_description' => $page->meta_description,
            'content' => $page->content,
            'is_active' => $page->is_active,
            'updated_at' => optional($page->updated_at)->toIso8601String(),
        ];
    }

    /** Меню для админки: плоские списки со всеми полями, включая скрытые. */
    public function adminMenu(): array
    {
        $items = MenuItem::query()
            ->orderBy('position')->orderBy('id')
            ->get()
            ->map(fn (MenuItem $item) => [
                'id' => $item->id,
                'area' => $item->area,
                'title' => $item->title,
                'url' => $item->url,
                'group' => $item->group,
                'is_active' => $item->is_active,
            ]);

        return [
            'header' => $items->where('area', 'header')->values()->all(),
            'footer' => $items->where('area', 'footer')->values()->all(),
        ];
    }

    /**
     * Сохранение меню из админки: страница присылает оба списка целиком
     * в нужном порядке, мы заменяем содержимое таблицы (как у контактов).
     * Порядок в массиве = position, id клиента не значимы.
     */
    public function replaceMenu(array $header, array $footer): array
    {
        DB::transaction(function () use ($header, $footer) {
            MenuItem::query()->delete();

            foreach (['header' => $header, 'footer' => $footer] as $area => $list) {
                foreach (array_values($list) as $position => $item) {
                    $group = trim((string) ($item['group'] ?? ''));

                    MenuItem::create([
                        'area' => $area,
                        'position' => $position,
                        'title' => trim($item['title']),
                        'url' => trim($item['url']),
                        // Раздел бывает только в шапке: в подвале legacy
                        // список плоский.
                        'group' => ($area === 'header' && $group !== '') ? $group : null,
                        'is_active' => (bool) ($item['is_active'] ?? true),
                    ]);
                }
            }
        });

        // Массовое удаление событий модели не вызывает — чистим кэш явно.
        self::forgetCache();

        return $this->adminMenu();
    }

    public static function forgetCache(): void
    {
        Cache::forget(self::CACHE_PAGES);
        Cache::forget(self::CACHE_MENU);
    }
}
