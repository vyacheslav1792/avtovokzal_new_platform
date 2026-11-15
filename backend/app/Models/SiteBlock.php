<?php

namespace App\Models;

use App\Services\SiteBlockService;
use Illuminate\Database\Eloquent\Model;

/**
 * Блок сайта («виджет»): кусок HTML в заранее отведённом месте страницы.
 * Перенос legacy avtovokzal57/vidjets/*.php (баннер над формой поиска,
 * приветствие, памятки на главной) и кусков, вбитых прямо в url/index.php.
 *
 * Ключ — слот из config/site_blocks.php; блоков с другими ключами сайт не
 * выводит, поэтому строка с незнакомым ключом ничего не поломает.
 */
class SiteBlock extends Model
{
    protected $guarded = [];

    protected $casts = [
        'is_active' => 'boolean',
        'publish_from' => 'datetime',
        'publish_to' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::saved(fn () => SiteBlockService::forgetCache());
        static::deleted(fn () => SiteBlockService::forgetCache());
    }
}
