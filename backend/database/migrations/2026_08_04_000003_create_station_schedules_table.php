<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Регулярное расписание станций (перенос legacy: там оно лежало
        // готовыми HTML-файлами url/raspisanie/<slug>.html, которые
        // перезаписывал getrasp.php?action=raspisanie).
        Schema::create('station_schedules', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('awtcod')->unique();
            $table->string('station_name', 128);
            // Транслит названия — как в именах legacy-файлов (krasnaya-zarya-kp),
            // пригодится для человекочитаемых адресов страницы расписания.
            $table->string('slug', 128)->unique();

            $table->jsonb('routes');                       // маршруты с графиком и временами
            $table->unsignedSmallInteger('routes_count')->default(0);

            // Когда данные сняты со станции и когда синхронизация выполнялась
            // (при ошибке остаётся прошлое расписание + текст ошибки).
            $table->timestampTz('generated_at')->nullable();
            $table->timestampTz('synced_at')->nullable();
            $table->text('sync_error')->nullable();

            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('station_schedules');
    }
};
