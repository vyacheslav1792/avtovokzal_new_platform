<?php

namespace App\Models;

use App\Services\PageService;
use Illuminate\Database\Eloquent\Model;

/**
 * Пункт меню сайта: шапка (area=header, у части пунктов group=«Пассажирам» —
 * выпадающий раздел legacy) или подвал (area=footer). В legacy оба списка
 * были захардкожены в avtovokzal57/index.php.
 */
class MenuItem extends Model
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
