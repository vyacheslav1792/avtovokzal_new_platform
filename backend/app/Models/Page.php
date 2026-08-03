<?php

namespace App\Models;

use App\Services\PageService;
use Illuminate\Database\Eloquent\Model;

/**
 * Обычная (текстовая) страница сайта — перенос legacy
 * avtovokzal57/url/<slug>.php вместе с <title>/description из index.php.
 * Содержимое правится в админке АИС (ais-spa, раздел «Страницы сайта»).
 */
class Page extends Model
{
    protected $guarded = [];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::saved(fn () => PageService::forgetCache());
        static::deleted(fn () => PageService::forgetCache());
    }
}
