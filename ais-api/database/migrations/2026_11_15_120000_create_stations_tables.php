<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /*
         * Справочник автостанций: то, что раньше правили в файле
         * (shared/stations.php, а до него $AWT в legacy inc/config.php и
         * ais/api/config.php — там он лежал ТРИЖДЫ и копии разъехались).
         *
         * Теперь источник один — эта таблица, а править её можно из АИС.
         * Файл остаётся резервом: пока таблица пуста, сервисы читают его
         * (локальный контур так и работает).
         */
        Schema::create('stations', function (Blueprint $table) {
            // Код станции (awtcod) — он же ключ: по нему станцию знают и
            // сайт, и BUSTER, и отчёты.
            $table->unsignedInteger('awtcod')->primary();
            $table->string('name', 128);

            // Бухгалтерские реквизиты выгрузок (legacy buh/buh_st/buh_ksr).
            $table->string('buh', 16)->nullable();
            $table->string('buh_st', 128)->nullable();
            $table->string('buh_ksr', 128)->nullable();

            // Ключ станционных обменов и порядок в списках (legacy inlist).
            $table->string('stationkey', 128)->nullable();
            $table->unsignedSmallInteger('inlist')->default(0);

            /*
             * Три выключателя, и они про разное:
             *  - is_active: станции «как будто нет» — её не видно ни на
             *    сайте, ни в отчётах, ни в списках АИС;
             *  - sales_enabled / refunds_enabled: станция есть, но продажа
             *    (или возврат) по ней не работает — билеты только в кассе.
             * Глобальные выключатели сайта (settings) действуют поверх.
             */
            $table->boolean('is_active')->default(true);
            $table->boolean('sales_enabled')->default(true);
            $table->boolean('refunds_enabled')->default(true);

            // Какой из трёх вариантов подключения действует сейчас.
            $table->unsignedSmallInteger('active_profile')->default(1);

            $table->timestampsTz();

            $table->index('inlist');
        });

        /*
         * Варианты подключения к базе станции — до трёх на станцию.
         * Отличаться может всё: адрес, порт, имя базы, пользователь и
         * пароль (в legacy для этого держали $AWT, $ALT_IP и отдельный
         * закомментированный блок, а переключали правкой $IP_MODE в коде).
         */
        Schema::create('station_profiles', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('station_awtcod');
            $table->unsignedSmallInteger('slot');          // 1..3
            $table->string('label', 64);                   // «Через VPN», «Локальный IP», …
            $table->string('db_host', 128);
            $table->unsignedInteger('db_port')->default(3050);
            $table->string('db_name', 255);
            $table->string('db_user', 64);
            // Пароль лежит как есть — ровно как лежал в файле конфига,
            // который читают оба сервиса. Шифровать Crypt'ом нельзя:
            // APP_KEY у ais-api и sales-api разные, и второй не расшифрует.
            // Наружу (в API АИС) пароль не отдаётся.
            $table->string('db_password', 255);
            $table->string('notes', 255)->nullable();

            // Результат последней проверки связи — её запускают из АИС.
            $table->timestampTz('checked_at')->nullable();
            $table->boolean('check_ok')->nullable();
            $table->string('check_error', 500)->nullable();

            $table->timestampsTz();

            $table->unique(['station_awtcod', 'slot']);
            $table->foreign('station_awtcod')
                ->references('awtcod')->on('stations')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('station_profiles');
        Schema::dropIfExists('stations');
    }
};
