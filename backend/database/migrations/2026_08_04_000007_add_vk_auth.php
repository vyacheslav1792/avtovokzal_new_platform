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
             * Вход через VK ID: e-mail перестаёт быть обязательным.
             * VK отдаёт адрес не всегда (у аккаунта его может не быть или
             * покупатель не дал доступ), а заставлять его придумывать —
             * терять покупателя. Такому пользователю почту предлагаем
             * добавить позже (auth_tokens type=email_confirm), а до тех пор
             * кабинет живёт без неё: заказы находятся по user_id, адрес для
             * билетов вводится в форме покупки.
             *
             * UNIQUE остаётся: в Postgres он допускает сколько угодно NULL.
             */
            $table->string('email')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('email')->nullable(false)->change();
        });
    }
};
