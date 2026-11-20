<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Остановка отправления в резерве и продаже (продажа «от остановки до
 * остановки», docs/BOARDING_POINTS.md).
 *
 * 0 — от своей автостанции, как было у всех прежних записей. Код хранится
 * вместе с названием: код (TRACES.STATCOD) нужен процедурам станции, а
 * название — журналу и отчётам, потому что у одной остановки в разных
 * направлениях станции разные коды.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reservations', function (Blueprint $table) {
            $table->unsignedInteger('start_statcod')->default(0)->after('statcod');
            $table->string('start_station', 64)->nullable()->after('start_statcod');
        });

        Schema::table('ticket_sales', function (Blueprint $table) {
            $table->unsignedInteger('start_statcod')->default(0)->after('statcod');
            $table->string('start_station', 64)->nullable()->after('start_statcod');
        });
    }

    public function down(): void
    {
        Schema::table('reservations', function (Blueprint $table) {
            $table->dropColumn(['start_statcod', 'start_station']);
        });

        Schema::table('ticket_sales', function (Blueprint $table) {
            $table->dropColumn(['start_statcod', 'start_station']);
        });
    }
};
