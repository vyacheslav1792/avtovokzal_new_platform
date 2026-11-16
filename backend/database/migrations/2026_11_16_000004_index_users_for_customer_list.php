<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Индексы под список покупателей в АИС (87 тыс. кабинетов): список
     * сортируется по дате появления кабинета, а фильтр «неподтверждённый
     * адрес» и сортировка по последнему входу ходят по своим колонкам.
     * Без них открытие страницы стоило больше секунды на сортировке.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->index('created_at');
            $table->index('last_login_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['created_at']);
            $table->dropIndex(['last_login_at']);
        });
    }
};
