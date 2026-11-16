<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Индексы по lower(email) для списка покупателей в АИС.
     *
     * Адреса сравниваются без учёта регистра (покупатель вводит их как
     * угодно), поэтому обычный индекс по `email` для таких условий не
     * годится — Postgres шёл последовательным чтением миллиона заказов на
     * КАЖДУЮ строку списка, и фильтр «с покупками» занимал почти две
     * секунды. Функциональные индексы это снимают.
     */
    public function up(): void
    {
        DB::statement('create index if not exists orders_email_lower_index on orders (lower(email))');
        DB::statement('create index if not exists users_email_lower_index on users (lower(email))');
    }

    public function down(): void
    {
        DB::statement('drop index if exists orders_email_lower_index');
        DB::statement('drop index if exists users_email_lower_index');
    }
};
