<?php

namespace Database\Seeders;

use App\Models\MenuItem;
use App\Models\SiteBlock;
use Illuminate\Database\Seeder;

/**
 * Первичное наполнение блоков сайта («виджетов») содержимым боевого
 * автовокзал57.рф: баннер над формой поиска и обращение коллектива
 * (legacy vidjets/top.php + url/index.php), приветствие, памятки
 * пассажирам (блок «Новости» из vidjets/news.php) и виджет dogs57.
 *
 * Заодно заводит пункт «Новости» в меню сайта: раздел появился, и до него
 * должна быть дорога. Меню уже наполнено (сид страниц отработал раньше),
 * поэтому пункт добавляется отдельно и только если его там нет.
 *
 * Идемпотентен: существующие блоки и пункты меню не трогает — дальше они
 * живут в админке. Повторный запуск безопасен, в том числе на бою.
 */
class SiteBlockSeeder extends Seeder
{
    /**
     * Содержимое — файлами рядом (database/data/site_blocks/<ключ>.html),
     * как у страниц сайта: HTML удобнее править и сверять с боевым файлом,
     * чем строкой в PHP.
     *
     * Слоты, которых здесь нет (schedule_top, trips_top, app_top), остаются
     * пустыми: в legacy на страницу расписания включался тот же блок памяток,
     * что и на главную, но дважды одно и то же показывать незачем.
     */
    private const BLOCKS = [
        'home_hero',
        'home_top',
        'home_welcome',
        'home_memos',
        'home_bottom',
    ];

    public function run(): void
    {
        foreach (self::BLOCKS as $key) {
            if (SiteBlock::query()->where('key', $key)->exists()) {
                $this->command?->info("Блок {$key} уже есть — пропущен.");

                continue;
            }

            $file = database_path("data/site_blocks/{$key}.html");

            if (! is_file($file)) {
                $this->command?->warn("Нет файла содержимого для блока {$key}.");

                continue;
            }

            SiteBlock::create([
                'key' => $key,
                'content' => trim(file_get_contents($file)),
                'is_active' => true,
            ]);
        }

        $this->addNewsMenuItem();
    }

    /**
     * Пункт «Новости» в шапке — сразу после расписания (так он стоит и в
     * мобильном меню, которое строится из того же списка), и в подвале.
     */
    private function addNewsMenuItem(): void
    {
        foreach (['header', 'footer'] as $area) {
            if (MenuItem::query()->where('area', $area)->where('url', '/news')->exists()) {
                continue;
            }

            if ($area === 'header') {
                $after = MenuItem::query()
                    ->where('area', 'header')
                    ->where('url', '/raspisanie-avtobusov')
                    ->value('position');

                $position = $after === null ? $this->nextPosition($area) : $after + 1;

                // Освобождаем место: позиции — это порядок в меню.
                MenuItem::query()
                    ->where('area', 'header')
                    ->where('position', '>=', $position)
                    ->increment('position');
            } else {
                $position = $this->nextPosition($area);
            }

            MenuItem::create([
                'area' => $area,
                'title' => 'Новости',
                'url' => '/news',
                'position' => $position,
                'is_active' => true,
            ]);
        }
    }

    private function nextPosition(string $area): int
    {
        return (int) MenuItem::query()->where('area', $area)->max('position') + 1;
    }
}
