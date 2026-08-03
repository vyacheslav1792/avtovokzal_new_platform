<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Заказы сайта (перенос legacy site.ORDERS в нормальную форму).
        Schema::create('orders', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // Рейс
            $table->unsignedInteger('awtcod');            // станция отправления
            $table->unsignedInteger('napcod');
            $table->unsignedInteger('raicecod');
            $table->unsignedInteger('statcod');           // станция назначения
            $table->string('from_name', 128);
            $table->string('to_name', 128);
            $table->string('trip_name', 128)->nullable();
            $table->string('carrier', 128)->nullable();
            $table->date('date_start');
            $table->time('time_start');
            $table->timestampTz('arrival_at')->nullable();

            // Резерв в API продаж
            $table->uuid('reservation_id')->nullable();
            $table->string('id_time_places', 32)->nullable()->index();

            // Пассажиры и билеты
            $table->jsonb('passengers');                  // как ввёл покупатель
            $table->jsonb('tickets')->nullable();         // после оформления: с номерами
            $table->decimal('total_price', 12, 2);

            // Покупатель
            $table->string('email', 255)->index();
            $table->string('phone', 32)->nullable();
            $table->string('ip', 45)->nullable();

            // Оплата
            $table->string('sber_order_id', 64)->nullable()->unique();
            $table->string('payment_url', 1000)->nullable();

            // Чек ККМ
            $table->string('fiscal_sign', 64)->nullable();

            // Возврат
            $table->string('back_code', 32)->nullable()->index();

            // pending → payment_registered → paid → completed | failed | cancelled
            $table->string('status', 24)->default('pending')->index();
            $table->text('status_reason')->nullable();

            $table->timestampTz('paid_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
