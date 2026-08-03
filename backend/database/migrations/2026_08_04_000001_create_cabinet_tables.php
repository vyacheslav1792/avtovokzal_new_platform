<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Личный кабинет покупателя (перенос legacy site.USERS).
        // Legacy хранил email + md5(соль+пароль) + PASSWORD_RAW (пароль
        // открытым текстом!) + CODE (одноразовый код письма). Здесь:
        // bcrypt через Hash, одноразовые коды — в отдельной таблице
        // auth_tokens со сроком жизни и отметкой использования.
        Schema::table('users', function (Blueprint $table) {
            // Имя необязательно: покупателя опознаём по e-mail.
            $table->string('name')->nullable()->change();
            // Пароль необязателен — задел на вход через VK / по коду из SMS,
            // когда пользователь заводится вообще без пароля.
            $table->string('password')->nullable()->change();

            $table->string('phone', 32)->nullable()->after('email');
            $table->timestampTz('phone_verified_at')->nullable()->after('phone');
            $table->timestampTz('last_login_at')->nullable();
        });

        // Способы входа. Пароль — такой же способ, как остальные: строка
        // с provider='password'. Под VK и телефон строки добавятся без
        // изменения схемы (provider='vk' / 'phone').
        Schema::create('user_identities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 24);            // password | vk | phone
            $table->string('provider_user_id', 128);   // e-mail, VK id, номер телефона
            $table->jsonb('payload')->nullable();      // ответ провайдера (профиль)
            $table->timestampTz('last_login_at')->nullable();
            $table->timestampsTz();

            $table->unique(['provider', 'provider_user_id']);
            $table->index(['user_id', 'provider']);
        });

        // Одноразовые ссылки из писем: приглашение после первой покупки
        // и сброс пароля. В базе только sha256 токена — как у Laravel
        // password_reset_tokens, но с TTL, историей и привязкой к типу.
        Schema::create('auth_tokens', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type', 24);                // invite | password_reset
            $table->string('email', 255);
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('token_hash', 64)->unique();
            $table->timestampTz('expires_at');
            $table->timestampTz('used_at')->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestampsTz();

            $table->index(['type', 'email']);
        });

        Schema::table('orders', function (Blueprint $table) {
            // Заказ авторизованного покупателя. В ЛК заказы всё равно ищутся
            // и по e-mail (покупки «на этот e-mail» до регистрации), но связь
            // нужна, чтобы заказ не потерялся при смене e-mail в профиле.
            $table->foreignId('user_id')->nullable()->after('email')->constrained()->nullOnDelete();
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex(['user_id']);
            $table->dropConstrainedForeignId('user_id');
        });

        Schema::dropIfExists('auth_tokens');
        Schema::dropIfExists('user_identities');

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['phone', 'phone_verified_at', 'last_login_at']);
            $table->string('name')->nullable(false)->change();
            $table->string('password')->nullable(false)->change();
        });
    }
};
