<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Заказ от пункта посадки — остановки на трассе рейса
 * (docs/BOARDING_POINTS.md).
 *
 * `from_name` остаётся тем, что видит покупатель (его показывают письма,
 * билет, чек и кабинет), поэтому у таких заказов там название пункта
 * посадки, а не станции. Рядом нужны ещё две вещи:
 *  - `from_stop` — название остановки в базе станции: по нему АИС и отчёты
 *    понимают, откуда человек сел, а поиск рейсов повторяет запрос;
 *  - `departure_at` — момент, когда автобус уходит С ОСТАНОВКИ: он на
 *    два часа позже отправления рейса, и именно его ждёт пассажир.
 *    `time_start` трогать нельзя — на нём держатся правила станции
 *    (срок возврата, отсечки) и сверки с её расписанием.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('boarding_point_slug', 64)->nullable()->after('from_name');
            $table->string('from_stop', 64)->nullable()->after('boarding_point_slug');
            $table->timestampTz('departure_at')->nullable()->after('time_start');

            $table->index('boarding_point_slug');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex(['boarding_point_slug']);
            $table->dropColumn(['boarding_point_slug', 'from_stop', 'departure_at']);
        });
    }
};
