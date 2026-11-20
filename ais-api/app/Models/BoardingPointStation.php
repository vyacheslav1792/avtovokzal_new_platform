<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Привязка пункта посадки к станционной базе: какая станция ведёт рейсы
 * и как в её TRACES называется остановка.
 *
 * Код остановки не хранится СОЗНАТЕЛЬНО: в разных направлениях одной
 * станции у остановки разные STATCOD, и код определяется по названию
 * вместе с направлением рейса (см. BusterGateway::boardingStop).
 */
class BoardingPointStation extends Model
{
    protected $guarded = [];

    protected $casts = [
        'awtcod' => 'int',
        'is_active' => 'bool',
        'inlist' => 'int',
        'excluded_destinations' => 'array',
    ];

    public function boardingPoint(): BelongsTo
    {
        return $this->belongsTo(BoardingPoint::class);
    }
}
