<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Выключатель автосборки расписания.
 *
 * Обычно половина «из баз станций» обновляется сама (`schedule:sync`), а
 * сотрудник только дописывает то, чего в базах нет. Но бывает наоборот:
 * база отдаёт расписание, которому нельзя верить (станция закрылась, а
 * рейсы в ней остались; маршруты заведены «для продажи», а не для
 * справки), и тогда расписание ведут полностью вручную.
 *
 * С этим признаком синк такое место пропускает: на сайте остаются только
 * маршруты из `manual_routes` (docs/BOARDING_POINTS.md).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('station_schedules', function (Blueprint $table) {
            $table->boolean('auto_disabled')->default(false)->after('manual_routes');
        });
    }

    public function down(): void
    {
        Schema::table('station_schedules', function (Blueprint $table) {
            $table->dropColumn('auto_disabled');
        });
    }
};
