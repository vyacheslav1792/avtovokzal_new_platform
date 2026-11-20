<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Куда можно уехать от пункта посадки — тот же кэш, что у станций
 * (`station_destinations`) и по той же причине: список читался бы из
 * Firebird на каждое открытие формы поиска, а меняется он от случая к
 * случаю.
 *
 * Строка на КАЖДУЮ привязку пункта к станции: один и тот же пункт
 * обслуживают разные базы (Дросково → Ливны ведёт Орёл, Дросково → Орёл
 * ведут Ливны), и в поиск нужно идти именно в ту станцию, у которой
 * нужный пункт назначения есть.
 *
 * Сама настройка пунктов живёт в БД АИС (`boarding_points`), здесь —
 * только результат опроса станций.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('boarding_point_destinations', function (Blueprint $table) {
            $table->id();
            // Ключ пункта посадки (slug в справочнике АИС) и станция.
            $table->string('slug', 64);
            $table->unsignedInteger('awtcod');
            $table->string('station_name', 64);

            $table->jsonb('destinations');
            $table->timestampTz('generated_at')->nullable();
            // Последняя неудача опроса: список при ней НЕ стирается.
            $table->string('sync_error', 500)->nullable();

            $table->timestampsTz();

            $table->unique(['slug', 'awtcod', 'station_name'], 'boarding_point_destinations_unique');
            $table->index('slug');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('boarding_point_destinations');
    }
};
