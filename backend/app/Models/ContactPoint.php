<?php

namespace App\Models;

use App\Services\ContactService;
use Illuminate\Database\Eloquent\Model;

/**
 * Остановочный пункт на странице контактов: автовокзал, автостанция или
 * кассовый пункт. Перенос нижней таблицы legacy url/contacts.php
 * (в legacy те же данные лежали ещё раз в мобильной вёрстке).
 */
class ContactPoint extends Model
{
    protected $guarded = [];

    protected $casts = [
        'work_hours' => 'array',
        'phones' => 'array',
        'breaks' => 'array',
        'is_active' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::saved(fn () => ContactService::forgetCache());
        static::deleted(fn () => ContactService::forgetCache());
    }
}
