<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * «Показывать станцию в расписании автобусов» — отдельно от того, работает
 * ли она.
 *
 * В legacy расписание закрытых станций жило готовыми HTML-файлами
 * (`url/raspisanie/<slug>.html`), и адрес `/raspisanie-avtobusov?from=…`
 * открывался даже у station, которой давно нет: людям нужно знать, что
 * через их село автобус всё ещё идёт. Эти файлы правили руками.
 *
 * Теперь то же решает выключатель: `is_active = false` убирает станцию из
 * продажи и отчётов, а `show_in_schedule = true` оставляет её в разделе
 * расписания — с расписанием, заведённым в АИС вручную
 * (см. docs/FRONTEND.md).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stations', function (Blueprint $table) {
            $table->boolean('show_in_schedule')->default(true)->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('stations', function (Blueprint $table) {
            $table->dropColumn('show_in_schedule');
        });
    }
};
