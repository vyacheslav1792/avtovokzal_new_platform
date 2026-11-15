<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Подключение к KkmServer (фискальные чеки). Вариантов может быть
 * несколько — боевая ККТ, эмулятор, резервная касса; действует тот, у
 * которого `is_active`. Ни одного активного — фискализация выключена.
 */
class KkmServer extends Model
{
    protected $table = 'kkm_servers';

    protected $guarded = [];

    protected $casts = [
        'slot' => 'int',
        'is_active' => 'bool',
        'num_device' => 'int',
        'command_timeout' => 'int',
        'checked_at' => 'datetime',
        'check_ok' => 'bool',
        'check_info' => 'array',
    ];

    /** Сколько вариантов настройки можно задать. */
    public const SLOTS = 3;

    /** Настройки для kkm-api — в том виде, в каком лежат в config/kkm.php. */
    public function toSettings(): array
    {
        return [
            'url' => $this->url,
            'user' => (string) $this->user,
            'password' => (string) $this->password,
            'num_device' => $this->num_device,
            'command_timeout' => $this->command_timeout,
            'cashier_name' => (string) $this->cashier_name,
        ];
    }
}
