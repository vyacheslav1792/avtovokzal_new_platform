<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Перенос расписаний, заведённых вручную, в отдельное поле.
 *
 * Пока ручное расписание лежало в `routes` под признаком `is_manual`, оно
 * было альтернативой автоматическому: либо из базы, либо руками. Теперь
 * части живут рядом (`routes` — из баз, `manual_routes` — руками), и
 * каждая станция или остановка может иметь обе: часть автобусов видна в
 * базах станций, часть дописывают для справки
 * (docs/BOARDING_POINTS.md).
 *
 * Поэтому прежние ручные записи переезжают в `manual_routes`, а
 * `is_manual` сбрасывается: защищать ручную работу от синка больше не
 * нужно — он пишет только свою половину.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('station_schedules')
            ->where('is_manual', true)
            ->update([
                'manual_routes' => DB::raw('routes'),
                'routes' => DB::raw(DB::getPdo()->quote('[]').'::jsonb'),
                'is_manual' => false,
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        DB::table('station_schedules')
            ->whereNotNull('manual_routes')
            ->update([
                'routes' => DB::raw('manual_routes'),
                'manual_routes' => null,
                'is_manual' => true,
                'updated_at' => now(),
            ]);
    }
};
