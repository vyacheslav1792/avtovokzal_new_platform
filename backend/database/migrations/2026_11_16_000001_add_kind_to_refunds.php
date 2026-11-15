<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('refunds', function (Blueprint $table) {
            /*
             * Вид возврата. Раньше возврат был один — билет целиком:
             *  - ticket  — билет (как было);
             *  - baggage — только багажные квитанции, билет остаётся
             *              действующим (на станции это отдельные строки
             *              SALE с категорией 9);
             *  - penalty — возврат УДЕРЖАНИЯ покупателю. На станции он не
             *              фиксируется вовсе: там удержание уже посчитано
             *              ведомостью, а мы лишь возвращаем деньги.
             */
            $table->string('kind', 16)->default('ticket')->after('number');

            // Сколько мест багажа вернули (у kind = baggage).
            $table->unsignedSmallInteger('baggage_count')->nullable()->after('kind');

            // Возврат удержания ссылается на возврат, по которому его взяли.
            $table->uuid('parent_refund_id')->nullable()->after('baggage_count');

            $table->index(['order_id', 'kind']);
            $table->foreign('parent_refund_id')->references('id')->on('refunds')->nullOnDelete();
        });

        /*
         * Прежняя уникальность (заказ + серия + номер) не пускала два
         * разных по смыслу возврата по одному бланку: возврат билета и
         * возврат удержания по нему же. Вид возврата входит в ключ.
         */
        Schema::table('refunds', function (Blueprint $table) {
            $table->dropUnique('refunds_order_id_serial_number_unique');
            $table->unique(['order_id', 'serial', 'number', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::table('refunds', function (Blueprint $table) {
            $table->dropUnique(['order_id', 'serial', 'number', 'kind']);
            $table->unique(['order_id', 'serial', 'number']);
            $table->dropForeign(['parent_refund_id']);
            $table->dropIndex(['order_id', 'kind']);
            $table->dropColumn(['kind', 'baggage_count', 'parent_refund_id']);
        });
    }
};
