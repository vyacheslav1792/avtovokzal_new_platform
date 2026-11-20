<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Пункт посадки — остановка на трассе рейса, с которой сайт продаёт
 * билеты (docs/BOARDING_POINTS.md).
 *
 * Своей базы продаж у него нет: рейсы ведут станции, перечисленные в
 * привязках, и в разные стороны — разные.
 */
class BoardingPoint extends Model
{
    protected $guarded = [];

    protected $casts = [
        'is_active' => 'bool',
        'show_on_home' => 'bool',
        'show_in_schedule' => 'bool',
        'inlist' => 'int',
    ];

    public function stations(): HasMany
    {
        return $this->hasMany(BoardingPointStation::class)->orderBy('inlist')->orderBy('id');
    }
}
