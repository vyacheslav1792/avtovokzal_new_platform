<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ручные маршруты РЯДОМ с автоматическими.
 *
 * У остановки (пункта посадки) расписание собирается из баз станций,
 * которые ведут проходящие рейсы, — но так видны не все автобусы: часть
 * рейсов не заходит ни на одну станцию из привязок, а людям о них знать
 * нужно, и раньше их дописывали руками в HTML-файл.
 *
 * Поэтому две части хранятся ОТДЕЛЬНО: `routes` обновляет
 * `schedule:sync` (авто), `manual_routes` правит сотрудник в АИС. Никто
 * не затирает чужую половину, а сайт показывает их вместе, по времени
 * (docs/BOARDING_POINTS.md).
 *
 * `is_manual` остаётся для мест, где автоматической части нет вовсе:
 * тогда синк не трогает и `routes`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('station_schedules', function (Blueprint $table) {
            $table->jsonb('manual_routes')->nullable()->after('routes');
        });
    }

    public function down(): void
    {
        Schema::table('station_schedules', function (Blueprint $table) {
            $table->dropColumn('manual_routes');
        });
    }
};
