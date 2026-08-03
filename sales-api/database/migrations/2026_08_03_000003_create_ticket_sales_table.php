<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Оформленные продажи (end_buy / KS_SALE_PLACES + багаж).
        Schema::create('ticket_sales', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('reservation_id')->nullable()->index();
            $table->string('agent_id', 64)->index();
            $table->unsignedInteger('awtcod');
            $table->unsignedInteger('napcod');
            $table->unsignedInteger('raicecod');
            $table->unsignedInteger('statcod');
            $table->date('date_start');
            $table->string('id_time_places', 32)->index();
            // Билеты с номерами: place, type, serial, number, price, пассажир, багаж
            $table->jsonb('tickets');
            $table->decimal('total_price', 12, 2)->default(0);
            $table->jsonb('raw_response')->nullable();
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_sales');
    }
};
