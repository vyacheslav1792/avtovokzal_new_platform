<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Расписание может принадлежать не только станции, но и ПУНКТУ ПОСАДКИ.
 *
 * У Дросково своей автостанции нет: на сайте это один пункт посадки
 * («Дросково»), от которого продаются билеты, и у него же должно быть
 * расписание проходящих автобусов — в legacy оно лежало отдельным
 * HTML-файлом. Без этого на сайте получались ДВА Дросково: пункт посадки
 * в поиске и «станция» с расписанием, чего в жизни нет
 * (docs/BOARDING_POINTS.md).
 *
 * Поэтому `awtcod` становится необязательным, а ключом расписания пункта
 * посадки — его `slug`. Ровно одна из двух привязок заполнена.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('station_schedules', function (Blueprint $table) {
            $table->string('boarding_point_slug', 64)->nullable()->after('awtcod');
        });

        // Уникальность — частичными индексами: одна строка на станцию и
        // одна на пункт посадки, при этом NULL в счёт не идёт.
        DB::statement('alter table station_schedules alter column awtcod drop not null');
        DB::statement('create unique index station_schedules_point_unique
            on station_schedules (boarding_point_slug) where boarding_point_slug is not null');
    }

    public function down(): void
    {
        DB::statement('drop index if exists station_schedules_point_unique');

        Schema::table('station_schedules', function (Blueprint $table) {
            $table->dropColumn('boarding_point_slug');
        });

        DB::statement('delete from station_schedules where awtcod is null');
        DB::statement('alter table station_schedules alter column awtcod set not null');
    }
};
