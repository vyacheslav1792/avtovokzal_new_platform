<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Вариант подключения к базе станции (до трёх на станцию).
 *
 * Варианты отличаются не только адресом: у станции может быть другое имя
 * базы, другой пользователь и пароль — в legacy для этого держали $AWT,
 * $ALT_IP и отдельный закомментированный блок, а переключались правкой
 * $IP_MODE прямо в коде.
 */
class StationProfile extends Model
{
    protected $guarded = [];

    protected $casts = [
        'slot' => 'int',
        'db_port' => 'int',
        'checked_at' => 'datetime',
        'check_ok' => 'bool',
    ];

    public function station(): BelongsTo
    {
        return $this->belongsTo(Station::class, 'station_awtcod', 'awtcod');
    }

    /** «192.168.4.250:43222/buster» — как это выглядит в списке АИС. */
    public function address(): string
    {
        return "{$this->db_host}:{$this->db_port}/{$this->db_name}";
    }
}
