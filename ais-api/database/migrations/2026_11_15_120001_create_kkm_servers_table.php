<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /*
         * Подключение к KkmServer (фискальные чеки) — раньше только
         * `kkm-api/config/kkm.php` + переменные окружения, то есть правка
         * файла и пересоздание контейнера. Теперь это справочник в АИС,
         * устроенный как у станций: несколько вариантов настройки и
         * переключение между ними.
         *
         * **Активен тот профиль, у которого is_active = true, и он один.**
         * Ни одного активного — фискализация выключена: kkm-api отвечает
         * отказом, а не пробивает чек «куда-нибудь». Пока таблица пуста,
         * kkm-api читает свой config/kkm.php (так работает локальный
         * контур и так же он переживёт недоступность БД АИС).
         */
        Schema::create('kkm_servers', function (Blueprint $table) {
            $table->id();
            $table->unsignedSmallInteger('slot')->unique();   // 1..3
            $table->string('label', 64);                      // «Боевая ККТ», «Эмулятор», …
            $table->boolean('is_active')->default(false);

            $table->string('url', 255);
            $table->string('user', 64)->nullable();
            // Как и у станций, пароль лежит как есть: его читает другое
            // приложение (kkm-api) со своим APP_KEY, поэтому Crypt не
            // подходит. Наружу API его не отдаёт.
            $table->string('password', 255)->nullable();

            // Номер устройства в KkmServer (0 — первое свободное).
            $table->unsignedSmallInteger('num_device')->default(1);
            // Сколько секунд KkmServer ждёт выполнения команды.
            $table->unsignedSmallInteger('command_timeout')->default(30);
            $table->string('cashier_name', 128)->nullable();

            $table->string('notes', 255)->nullable();

            // Результат последней проверки связи из АИС.
            $table->timestampTz('checked_at')->nullable();
            $table->boolean('check_ok')->nullable();
            $table->string('check_error', 500)->nullable();
            // Что ответила ККТ при проверке: модель, номер ФН, смена.
            $table->jsonb('check_info')->nullable();

            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kkm_servers');
    }
};
