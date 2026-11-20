<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /*
         * ПУНКТЫ ПОСАДКИ — остановки на трассе рейсов, с которых сайт
         * продаёт билеты («Дросково КП → Ливны АС» на рейсе Орёл–Ливны).
         * Разбор механизма — docs/BOARDING_POINTS.md.
         *
         * Своей базы продаж у такого пункта нет (у Дросково станция
         * закрыта): рейсы ведут ДРУГИЕ станции, причём в разные стороны
         * разные — от Дросково в Ливны везёт рейс из базы Орла, а в Орёл —
         * рейс из базы Ливен. Поэтому пункт посадки = название для
         * покупателя плюс привязки к станционным базам.
         */
        Schema::create('boarding_points', function (Blueprint $table) {
            $table->id();

            // Ключ в адресах сайта и в заказах (`orders.from_slug`): менять
            // его нельзя, иначе прежние ссылки и история заказов осиротеют.
            $table->string('slug', 64)->unique();

            // Название, которое видит покупатель в «Откуда». Может
            // отличаться от названия остановки в базе станции: там
            // «Дросково КП», а на сайте достаточно «Дросково».
            $table->string('name', 128);

            $table->boolean('is_active')->default(true);
            // Где показывать: форма поиска на главной и/или расписание.
            $table->boolean('show_on_home')->default(true);
            $table->boolean('show_in_schedule')->default(true);
            $table->unsignedSmallInteger('inlist')->default(0);
            $table->string('note', 255)->nullable();

            $table->timestampsTz();

            $table->index('inlist');
        });

        /*
         * Привязки пункта посадки к станционным базам.
         *
         * Хранится НАЗВАНИЕ остановки, а не её код: у одной остановки в
         * разных направлениях станции разные STATCOD (у «Дросково КП» в
         * базе Орла — 3, 11 и 12 сразу), поэтому код определяется по
         * названию и направлению рейса в момент запроса.
         */
        Schema::create('boarding_point_stations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('boarding_point_id')
                ->constrained('boarding_points')
                ->cascadeOnDelete();

            // Станция, чья база ведёт рейсы (awtcod). Без внешнего ключа:
            // пока справочник станций пуст, сервисы работают по файлу
            // shared/stations.php, и ссылка на пустую таблицу мешала бы.
            $table->unsignedInteger('awtcod');

            // Название остановки в TRACES.STATNAME этой базы.
            $table->string('station_name', 64);

            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('inlist')->default(0);

            /*
             * Пункты назначения, которые НЕ продаём, хотя тариф заведён
             * (список названий). Нужен, когда станция завела тариф «на
             * будущее» или продажа через сайт нежелательна.
             */
            $table->jsonb('excluded_destinations')->nullable();

            $table->timestampsTz();

            $table->unique(['boarding_point_id', 'awtcod', 'station_name'], 'boarding_point_station_unique');
            $table->index('awtcod');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('boarding_point_stations');
        Schema::dropIfExists('boarding_points');
    }
};
