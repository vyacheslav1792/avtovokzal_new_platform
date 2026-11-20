<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Автостанция: то, что раньше правили в файле (shared/stations.php, а до
 * него $AWT в legacy — там он лежал трижды и копии разъехались).
 *
 * Выключатели про РАЗНОЕ: `is_active` убирает станцию совсем («её как
 * будто нет»), `sales_enabled` и `refunds_enabled` оставляют станцию в
 * списках, но без продажи или без возврата через сайт, а
 * `show_in_schedule` держит её в разделе «Расписание автобусов» — даже
 * закрытую и без базы продаж, с расписанием, заведённым вручную в АИС
 * (в legacy для этого правили готовый HTML-файл).
 */
class Station extends Model
{
    /** awtcod — естественный ключ: по нему станцию знают сайт, BUSTER и отчёты. */
    protected $primaryKey = 'awtcod';

    public $incrementing = false;

    protected $keyType = 'int';

    protected $guarded = [];

    protected $casts = [
        'awtcod' => 'int',
        'inlist' => 'int',
        'is_active' => 'bool',
        'show_in_schedule' => 'bool',
        'sales_enabled' => 'bool',
        'refunds_enabled' => 'bool',
        'active_profile' => 'int',
    ];

    /** Сколько вариантов подключения можно задать одной станции. */
    public const PROFILE_SLOTS = 3;

    public function profiles(): HasMany
    {
        return $this->hasMany(StationProfile::class, 'station_awtcod', 'awtcod')->orderBy('slot');
    }

    /** Действующий вариант подключения. */
    public function activeProfile(): ?StationProfile
    {
        return $this->profiles->firstWhere('slot', $this->active_profile);
    }

    /**
     * Станция в том виде, в каком её ждут сервисы (формат прежнего
     * конфига shared/stations.php) — с реквизитами действующего профиля.
     *
     * @return array<string, mixed>|null null — подключение не настроено
     */
    public function toRegistryEntry(): ?array
    {
        $profile = $this->activeProfile();

        if (! $profile) {
            return null;
        }

        return [
            'name' => $this->name,
            'db_host' => $profile->db_host,
            'db_port' => $profile->db_port,
            'db_name' => $profile->db_name,
            'db_user' => $profile->db_user,
            'db_password' => $profile->db_password,
            'buh' => $this->buh,
            'buh_st' => $this->buh_st,
            'buh_ksr' => $this->buh_ksr,
            'stationkey' => $this->stationkey,
            'inlist' => $this->inlist,
            // Выключатели станции: продажа и возврат через сайт.
            'sales_enabled' => $this->sales_enabled,
            'refunds_enabled' => $this->refunds_enabled,
        ];
    }
}
