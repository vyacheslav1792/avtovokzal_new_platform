<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            /*
             * Пароль кабинета со старого сайта: md5(соль + пароль), как его
             * хранил legacy (`USERS.PASSWORD`, соль — `inc/config.php`).
             *
             * Нужен ровно для одного: пустить покупателя прежним паролем
             * после переезда. При первом успешном входе пароль
             * пересохраняется в bcrypt (`users.password`), а это поле
             * очищается — см. AuthService::login. Открытый пароль
             * (`USERS.PASSWORD_RAW`) в новую базу НЕ переносится вовсе.
             */
            $table->string('legacy_password', 32)->nullable()->after('password');
            // Дата регистрации на старом сайте (USERS.DATE_REG) переносится
            // в created_at, поэтому отдельного поля не нужно.
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('legacy_password');
        });
    }
};
