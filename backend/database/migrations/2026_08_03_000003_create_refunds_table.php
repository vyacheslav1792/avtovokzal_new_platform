<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Возвраты билетов (перенос legacy site.BACKS): одна строка — один билет.
        Schema::create('refunds', function (Blueprint $table) {
            $table->uuid('id')->primary();
            // orders.id — varchar(36) (см. миграцию заказов), не uuid.
            $table->string('order_id', 36);
            $table->foreign('order_id')->references('id')->on('orders');

            // Возвращаемый билет
            $table->string('serial', 20);
            $table->string('number', 20);
            $table->unsignedSmallInteger('place')->nullable();

            // Деньги
            $table->unsignedSmallInteger('penalty_percent');
            $table->decimal('ticket_price', 12, 2);
            $table->decimal('baggage_price', 12, 2)->default(0);
            $table->decimal('amount', 12, 2);          // итог к возврату на карту

            // refunded → money_refunded | bank_failed (вернёт бухгалтерия)
            $table->string('status', 24)->default('refunded')->index();
            $table->text('bank_error')->nullable();
            $table->timestampTz('money_refunded_at')->nullable();

            // Чек ККМ возврата
            $table->string('fiscal_sign', 64)->nullable();

            // Хвост полей legacy site.BACKS: WHO_BACK, TYPE_BACK, построчные
            // суммы (STRAH/BIL/BAGAJ и их *_BACK), DATE_ORDER2, DATESTART2.
            $table->jsonb('legacy')->nullable();

            $table->timestampsTz();

            $table->unique(['order_id', 'serial', 'number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('refunds');
    }
};
