<?php

namespace App\Models;

use App\Services\SettingService;
use Illuminate\Database\Eloquent\Model;

/**
 * Настройка сайта из АИС (продажа и возврат билетов). Ключ — из
 * config/settings.php; значение любого типа, поэтому хранится как JSON.
 */
class Setting extends Model
{
    protected $primaryKey = 'key';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $casts = [
        'value' => 'array',
    ];

    protected static function booted(): void
    {
        static::saved(fn () => SettingService::forgetCache());
        static::deleted(fn () => SettingService::forgetCache());
    }
}
