<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * АТП по договору: его билеты не идут в расчёт отчёта «Нулевые билеты»
 * (галочка «Исключить АТП по договорам»). Сверка по НАЗВАНИЮ, как в legacy.
 *
 * `kind` — вид рейса: списки у пригородных, внутриобластных и
 * межобластных разные (в legacy это были разные файлы).
 */
class LgotExcludedAtp extends Model
{
    protected $guarded = [];

    protected $casts = ['position' => 'int'];
}
