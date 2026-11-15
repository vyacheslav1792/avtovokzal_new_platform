<?php

namespace App\Services;

use App\Models\News;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Новости и объявления сайта. В legacy это был vidjets/news.php: объявления
 * вперемешку с вёрсткой, срок показа — условие time() < strtotime('…')
 * в исходнике, а «архив» — закомментированные блоки в том же файле.
 *
 * Список читается на каждом заходе на главную, поэтому кэшируется. В кэш
 * кладём ТОЛЬКО краткие сведения (без content): содержимое новостей на
 * списке не нужно, а в кэше драйвера database оно раздувало бы запись.
 */
class NewsService
{
    private const CACHE_LIST = 'news:public:list';

    private const CACHE_TTL = 300;

    /** Сколько новостей на странице списка по умолчанию. */
    public const PER_PAGE = 10;

    /**
     * Краткие сведения обо всех видимых новостях, свежие сверху.
     *
     * @return array<int, array<string, mixed>>
     */
    public function publicList(): array
    {
        return Cache::remember(self::CACHE_LIST, self::CACHE_TTL, function () {
            return News::query()
                ->visible()
                ->orderByDesc('published_at')
                ->orderByDesc('id')
                ->get([
                    'id', 'slug', 'title', 'lead', 'cover_url', 'cover_alt',
                    'published_at', 'on_index',
                ])
                ->map(fn (News $news) => [
                    'slug' => $news->slug,
                    'title' => $news->title,
                    'lead' => $news->lead,
                    'cover_url' => $news->cover_url,
                    'cover_alt' => $news->cover_alt,
                    'published_at' => $news->published_at->toIso8601String(),
                    'on_index' => $news->on_index,
                ])
                // В кэш кладём массивы: Eloquent Collection в database-кэше
                // десериализуется в incomplete class.
                ->all();
        });
    }

    /**
     * Страница списка для сайта.
     *
     * @return array{items: array<int, array<string, mixed>>, total: int, page: int, per_page: int, last_page: int}
     */
    public function page(int $page = 1, int $perPage = self::PER_PAGE, bool $featuredOnly = false): array
    {
        $all = $this->publicList();

        if ($featuredOnly) {
            $all = array_values(array_filter($all, fn (array $item) => $item['on_index']));
        }

        $total = count($all);
        $perPage = max(1, min($perPage, 50));
        $lastPage = max(1, (int) ceil($total / $perPage));
        $page = max(1, min($page, $lastPage));

        return [
            'items' => array_slice($all, ($page - 1) * $perPage, $perPage),
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'last_page' => $lastPage,
        ];
    }

    /**
     * Новость по адресу; null — если её нет, она выключена или срок вышел.
     *
     * Не кэшируется, в отличие от списка: страницу новости открывают
     * несопоставимо реже главной, а выборка идёт по уникальному slug.
     */
    public function publicItem(string $slug): ?array
    {
        $news = News::query()->visible()->where('slug', $slug)->first();

        if ($news === null) {
            return null;
        }

        return [
            'slug' => $news->slug,
            'title' => $news->title,
            'meta_title' => $news->meta_title ?: $news->title,
            'meta_description' => $news->meta_description,
            'lead' => $news->lead,
            'content' => $news->content,
            'cover_url' => $news->cover_url,
            'cover_alt' => $news->cover_alt,
            'published_at' => $news->published_at->toIso8601String(),
            'updated_at' => optional($news->updated_at)->toIso8601String(),
        ];
    }

    /** Список для админки: со всеми новостями, включая выключенные. */
    public function adminList(): array
    {
        return News::query()
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->get([
                'id', 'slug', 'title', 'cover_url', 'published_at',
                'publish_from', 'publish_to', 'is_active', 'on_index', 'updated_at',
            ])
            ->map(fn (News $news) => [
                'id' => $news->id,
                'slug' => $news->slug,
                'title' => $news->title,
                'cover_url' => $news->cover_url,
                'published_at' => $news->published_at->toIso8601String(),
                'publish_from' => optional($news->publish_from)->toIso8601String(),
                'publish_to' => optional($news->publish_to)->toIso8601String(),
                'is_active' => $news->is_active,
                'on_index' => $news->on_index,
                'updated_at' => optional($news->updated_at)->toIso8601String(),
                // Видна ли сейчас на сайте: сотруднику важнее факта «включена»
                // — объявление с прошедшим сроком включено, но не видно.
                'visible' => $this->isVisible($news),
            ])
            ->all();
    }

    /** Новость целиком для админки. */
    public function adminItem(News $news): array
    {
        return [
            'id' => $news->id,
            'slug' => $news->slug,
            'title' => $news->title,
            'meta_title' => $news->meta_title,
            'meta_description' => $news->meta_description,
            'lead' => $news->lead,
            'content' => $news->content,
            'cover_url' => $news->cover_url,
            'cover_alt' => $news->cover_alt,
            'published_at' => $news->published_at->toIso8601String(),
            'publish_from' => optional($news->publish_from)->toIso8601String(),
            'publish_to' => optional($news->publish_to)->toIso8601String(),
            'is_active' => $news->is_active,
            'on_index' => $news->on_index,
            'updated_at' => optional($news->updated_at)->toIso8601String(),
            'visible' => $this->isVisible($news),
        ];
    }

    private function isVisible(News $news): bool
    {
        if (! $news->is_active) {
            return false;
        }

        $now = Carbon::now();

        return ! ($news->publish_from && $news->publish_from->gt($now))
            && ! ($news->publish_to && $news->publish_to->lt($now));
    }

    public static function forgetCache(): void
    {
        Cache::forget(self::CACHE_LIST);
    }
}
