<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /*
         * Код подтверждения из письма: 6 цифр вместо одноразовой ссылки
         * (auth_tokens). Ссылки часто уводят письмо в спам, а код
         * покупатель переносит руками из любого почтового клиента.
         *
         * Счётчик попыток живёт здесь, а не в сессии браузера: требование —
         * пять попыток на пару «адрес + код» независимо от того, откуда их
         * вводят. Поэтому активный код на адрес ровно один: выпуск нового
         * гасит прежний (см. AuthCodeService::issue).
         */
        Schema::create('auth_codes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type', 24);                // register | password_reset | email_confirm
            $table->string('email', 255);
            // Регистрация идёт до создания пользователя, поэтому nullable.
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            // bcrypt, а не sha256: у шестизначного кода всего миллион
            // значений, и радужная таблица к sha256 строится мгновенно.
            $table->string('code_hash');
            $table->unsignedSmallInteger('attempts')->default(0);
            /*
             * Данные незавершённой регистрации: хэш пароля и телефон.
             * Пользователя создаём только после подтверждения адреса —
             * иначе неподтверждённый кабинет занимал бы чужой e-mail,
             * а в базе росли бы мёртвые записи.
             */
            $table->jsonb('payload')->nullable();
            $table->timestampTz('expires_at');
            // Использован либо погашен выпуском нового кода.
            $table->timestampTz('used_at')->nullable();
            // Исчерпаны попытки ввода — нужен новый код.
            $table->timestampTz('blocked_at')->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestampsTz();

            // Поиск активного кода и подсчёт суточного лимита.
            $table->index(['type', 'email', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('auth_codes');
    }
};
