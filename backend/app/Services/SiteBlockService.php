<?php

namespace App\Services;

use App\Models\SiteBlock;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Блоки сайта («виджеты»): редактируемые куски HTML в заранее отведённых
 * местах страниц. Перенос legacy avtovokzal57/vidjets/*.php и кусков,
 * вбитых прямо в url/index.php: приветствие, обращение и памятки правились
 * выкладкой кода на боевой сайт.
 *
 * Список слотов — config/site_blocks.php, и он главный: блок с ключом не из
 * каталога сайту неизвестен и не выводится.
 */
class SiteBlockService
{
    private const CACHE_PUBLIC = 'site-blocks:public';

    private const CACHE_TTL = 300;

    /**
     * Содержимое блоков для сайта: ключ слота → HTML. Выключенные и те, чей
     * срок показа не наступил или вышел, в ответ не попадают — сайт не
     * должен решать, показывать блок или нет.
     *
     * @return array<string, string>
     */
    public function publicBlocks(): array
    {
        return Cache::remember(self::CACHE_PUBLIC, self::CACHE_TTL, function () {
            $now = Carbon::now();

            return SiteBlock::query()
                ->where('is_active', true)
                ->whereIn('key', array_keys(self::slots()))
                ->get()
                ->filter(fn (SiteBlock $block) => ! ($block->publish_from && $block->publish_from->gt($now))
                    && ! ($block->publish_to && $block->publish_to->lt($now)))
                ->reject(fn (SiteBlock $block) => trim((string) $block->content) === '')
                ->mapWithKeys(fn (SiteBlock $block) => [$block->key => $block->content])
                ->all();
        });
    }

    /**
     * Каталог слотов и их содержимое для админки: слот есть в ответе даже
     * если блок ещё не заводили — сотрудник должен видеть все места, куда
     * можно что-то написать.
     *
     * @return array<int, array<string, mixed>>
     */
    public function adminBlocks(): array
    {
        $saved = SiteBlock::query()->get()->keyBy('key');
        $now = Carbon::now();

        $blocks = [];

        foreach (self::slots() as $key => $slot) {
            /** @var SiteBlock|null $block */
            $block = $saved->get($key);

            $blocks[] = [
                'key' => $key,
                'title' => $slot['title'],
                'hint' => $slot['hint'],
                'page' => $slot['page'] ?? null,
                'content' => $block->content ?? '',
                'is_active' => $block === null ? true : $block->is_active,
                'publish_from' => optional($block?->publish_from)->toIso8601String(),
                'publish_to' => optional($block?->publish_to)->toIso8601String(),
                'updated_at' => optional($block?->updated_at)->toIso8601String(),
                // Видно ли сейчас: включённый блок с прошедшим сроком на
                // сайте не появится, и по галочке этого не понять.
                'visible' => $block !== null
                    && $block->is_active
                    && trim((string) $block->content) !== ''
                    && ! ($block->publish_from && $block->publish_from->gt($now))
                    && ! ($block->publish_to && $block->publish_to->lt($now)),
            ];
        }

        return $blocks;
    }

    /**
     * Сохранение блоков из админки. Присылается весь список слотов, как у
     * меню и контактов; незнакомые ключи молча игнорируются, пустой блок
     * удаляется — пустая строка в таблице ничего не значит.
     *
     * @param  array<int, array<string, mixed>>  $blocks
     */
    public function replace(array $blocks): array
    {
        $slots = self::slots();

        DB::transaction(function () use ($blocks, $slots) {
            foreach ($blocks as $item) {
                $key = (string) ($item['key'] ?? '');

                if (! isset($slots[$key])) {
                    continue;
                }

                $content = (string) ($item['content'] ?? '');

                if (trim($content) === '') {
                    SiteBlock::query()->where('key', $key)->delete();

                    continue;
                }

                SiteBlock::query()->updateOrCreate(['key' => $key], [
                    'content' => $content,
                    'is_active' => (bool) ($item['is_active'] ?? true),
                    'publish_from' => $item['publish_from'] ?: null,
                    'publish_to' => $item['publish_to'] ?: null,
                ]);
            }
        });

        // Массовое удаление событий модели не вызывает — чистим кэш явно.
        self::forgetCache();

        return $this->adminBlocks();
    }

    /** @return array<string, array<string, mixed>> */
    private static function slots(): array
    {
        return config('site_blocks', []);
    }

    public static function forgetCache(): void
    {
        Cache::forget(self::CACHE_PUBLIC);
    }
}
