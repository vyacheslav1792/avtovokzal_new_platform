<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Билет, исключённый из расчёта вручную (галочка «Применять исключение
 * билетов»). `data` — снимок строки отчёта: билет мог уехать из выбранного
 * периода, и показать его в списке исключённых больше нечем.
 *
 * `kind` — вид рейса: список свой у каждого (в legacy он был только у
 * пригородных, файлом TICKET_ISKLUCH_PRIG.txt).
 */
class LgotExcludedTicket extends Model
{
    protected $guarded = [];

    protected $casts = ['data' => 'array'];
}
