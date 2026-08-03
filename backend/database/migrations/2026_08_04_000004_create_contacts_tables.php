<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Контакты сайта (перенос legacy url/contacts.php — там страница была
        // статическим HTML, и любая правка телефона требовала выкладки кода,
        // причём данные лежали ДВАЖДЫ: таблица для десктопа и карточки для
        // мобильных). Здесь — данные; вёрстку обеих версий делает фронт.

        // Реквизиты организации (верхняя таблица «название — значение»).
        Schema::create('contact_details', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('position')->default(0);
            $table->string('label', 255);              // «Юридический адрес»
            $table->string('kind', 16)->default('text'); // text|phone|email|fax
            // Значения строкой списка: у «Справочной» их два телефона.
            // Колонка не `values` — это зарезервированное слово SQL.
            $table->jsonb('items');
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
        });

        // Остановочные пункты: автовокзалы, автостанции, кассовые пункты.
        Schema::create('contact_points', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('position')->default(0);
            $table->string('name', 255);               // «Кассовый пункт «Болхов»»
            $table->jsonb('work_hours');               // строки: «пн-сб 6:15 – 17:15»
            $table->jsonb('phones');                   // строки телефонов
            $table->string('address', 500)->nullable();
            $table->jsonb('breaks');                   // строки перерывов
            // Скрытый пункт остаётся в базе, но на сайт не выводится — так в
            // legacy жили закомментированные Нарышкино и Знаменское.
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contact_points');
        Schema::dropIfExists('contact_details');
    }
};
