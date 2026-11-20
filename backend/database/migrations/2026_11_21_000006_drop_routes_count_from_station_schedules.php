<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Удаление `routes_count`: число маршрутов считается на лету.
 *
 * Колонка появилась, когда расписание было одним списком. После
 * разделения на половины (`routes` из баз + `manual_routes` руками) её
 * писал только `schedule:sync`, и то лишь автоматическую часть:
 * редактор АИС и удаление ручных маршрутов её не трогали. Остальные
 * читатели — страница сайта, список раздела, список в АИС — считали
 * обе половины сами, а панель «Обновление данных» читала колонку, и
 * показывала другое число (на стенде 5 против 6 у Дросково).
 *
 * Производное значение, которое надо не забыть обновить в четырёх
 * местах, так и ломается. Считаем везде одинаково — по данным.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('station_schedules', function (Blueprint $table) {
            $table->dropColumn('routes_count');
        });
    }

    public function down(): void
    {
        Schema::table('station_schedules', function (Blueprint $table) {
            $table->unsignedSmallInteger('routes_count')->default(0)->after('routes');
        });

        // Возвращаем колонку заполненной: обе половины, как их считает
        // приложение, — иначе откат оставил бы нули.
        DB::statement(<<<'SQL'
            update station_schedules
               set routes_count = jsonb_array_length(coalesce(routes, '[]'::jsonb))
                                + jsonb_array_length(coalesce(manual_routes, '[]'::jsonb))
        SQL);
    }
};
