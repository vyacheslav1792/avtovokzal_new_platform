<?php

namespace App\Models;

use App\Services\ContactService;
use Illuminate\Database\Eloquent\Model;

/**
 * Строка реквизитов на странице контактов: «название — значение(я)»
 * (юридический адрес, справочная, почта). Перенос верхней таблицы
 * legacy url/contacts.php.
 */
class ContactDetail extends Model
{
    protected $guarded = [];

    protected $casts = [
        'items' => 'array',
        'is_active' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::saved(fn () => ContactService::forgetCache());
        static::deleted(fn () => ContactService::forgetCache());
    }
}
