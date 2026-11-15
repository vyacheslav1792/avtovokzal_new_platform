<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Удержание возвращается ЧАСТЯМИ, поэтому строк `penalty` по одному
     * бланку может быть несколько.
     *
     * Уникальность (заказ + серия + номер + вид) была рассчитана на «одно
     * удержание на возврат»: первый частичный возврат проходил, а второй
     * падал на `refunds_order_id_serial_number_kind_unique` — сотрудник
     * видел «Server Error» (ловилось на проде 16.11.2026: вернули 2.00 из
     * 5.57, остаток 3.57 отдать уже не получалось).
     *
     * Для билета и багажа уникальность нужна и остаётся — она закрывает
     * повторный возврат того же бланка. Для удержания её заменяет проверка
     * остатка в коде (`AdminRefundService::penaltyLeft`).
     */
    public function up(): void
    {
        Schema::table('refunds', function (Blueprint $table) {
            $table->dropUnique('refunds_order_id_serial_number_kind_unique');
        });

        // Частичный индекс: Blueprint такого не умеет, поэтому SQL.
        DB::statement(
            'create unique index refunds_order_id_serial_number_kind_unique
                on refunds (order_id, serial, number, kind)
                where kind <> \'penalty\''
        );
    }

    public function down(): void
    {
        DB::statement('drop index if exists refunds_order_id_serial_number_kind_unique');

        Schema::table('refunds', function (Blueprint $table) {
            $table->unique(['order_id', 'serial', 'number', 'kind']);
        });
    }
};
