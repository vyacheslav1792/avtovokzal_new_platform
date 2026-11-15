<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Пункты назначения станции (список «Куда» на форме поиска), снятые с
 * базы продаж. Раньше KS_STATIONS опрашивалась на каждый запрос формы.
 */
class StationDestination extends Model
{
    protected $guarded = [];

    protected $casts = [
        'destinations' => 'array',
        'generated_at' => 'datetime',
        'synced_at' => 'datetime',
    ];
}
