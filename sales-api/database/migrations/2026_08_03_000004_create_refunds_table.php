<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Возвраты/аннулирования билетов (tickets_back / KS_ANN_TICKET_FROM_AW).
        Schema::create('refunds', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('agent_id', 64)->index();
            $table->unsignedInteger('awtcod');
            $table->unsignedTinyInteger('operation');   // 1 возврат | 5 аннулирование
            $table->boolean('preview')->default(false); // только расчёт суммы, без возврата
            $table->jsonb('tickets');                   // [{serial, number, price}]
            $table->decimal('total_refund', 12, 2)->nullable();
            $table->jsonb('raw_response')->nullable();
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('refunds');
    }
};
