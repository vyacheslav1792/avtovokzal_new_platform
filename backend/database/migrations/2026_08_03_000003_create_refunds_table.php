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
            $table->foreignUuid('order_id')->constrained('orders');

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

            $table->timestampsTz();

            $table->unique(['order_id', 'serial', 'number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('refunds');
    }
};
