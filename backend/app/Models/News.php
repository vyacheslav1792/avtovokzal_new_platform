<?php

namespace App\Models;

use App\Services\NewsService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Новость (объявление) сайта. В legacy раздела новостей не было: объявления
 * лежали в avtovokzal57/vidjets/news.php вперемешку с вёрсткой, а срок показа
 * задавался условием time() < strtotime('…') в самом исходнике.
 *
 * Публикуется и правится в админке АИС (ais-spa, раздел «Новости»).
 */
class News extends Model
{
    protected $table = 'news';

    protected $guarded = [];

    protected $casts = [
        'published_at' => 'datetime',
        'publish_from' => 'datetime',
        'publish_to' => 'datetime',
        'is_active' => 'boolean',
        'on_index' => 'boolean',
    ];

    /**
     * Видно на сайте: включена и попадает в срок показа. Отсутствие
     * publish_from/publish_to означает «без ограничения» — у постоянных
     * объявлений срока нет.
     */
    public function scopeVisible(Builder $query, ?Carbon $now = null): Builder
    {
        $now ??= Carbon::now();

        return $query
            ->where('is_active', true)
            ->where(fn (Builder $q) => $q->whereNull('publish_from')->orWhere('publish_from', '<=', $now))
            ->where(fn (Builder $q) => $q->whereNull('publish_to')->orWhere('publish_to', '>=', $now));
    }

    protected static function booted(): void
    {
        static::saved(fn () => NewsService::forgetCache());
        static::deleted(fn () => NewsService::forgetCache());
    }
}
