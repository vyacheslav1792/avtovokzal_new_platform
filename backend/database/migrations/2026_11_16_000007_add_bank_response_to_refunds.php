<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Полный ответ шлюза при отказе в возврате.
     *
     * В `bank_error` попадает только короткий текст — шлюз на отказ
     * отвечает «Системная ошибка.», и по этой строке разобраться нельзя
     * (ловилось 16.11.2026: пять попыток вернуть удержание 8,95 ₽, в
     * журнале у всех одно и то же «Системная ошибка.», а настоящая
     * причина — платёж уже в состоянии REFUNDED и шлюз не принимает
     * возврат остатка).
     *
     * Поэтому рядом храним всё, что известно о попытке: код и класс
     * ошибки, сумму, снимок состояния платежа в шлюзе. Это показывается
     * сотруднику в карточке возврата — чтобы он видел причину, а не
     * «Системную ошибку».
     */
    public function up(): void
    {
        Schema::table('refunds', function (Blueprint $table) {
            $table->jsonb('bank_response')->nullable()->after('bank_error');
        });
    }

    public function down(): void
    {
        Schema::table('refunds', function (Blueprint $table) {
            $table->dropColumn('bank_response');
        });
    }
};
