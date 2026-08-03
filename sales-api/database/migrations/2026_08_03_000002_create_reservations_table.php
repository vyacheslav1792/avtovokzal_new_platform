<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Резервы мест (start_buy / KS_BRONTIME_PLACE).
        Schema::create('reservations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('agent_id', 64)->index();
            $table->unsignedInteger('awtcod');
            $table->unsignedInteger('napcod');
            $table->unsignedInteger('raicecod');
            $table->unsignedInteger('statcod');
            $table->date('date_start');
            $table->jsonb('places');                    // ["3","4"]
            // TIMEMARK резерва в SALONINFO, строкой с долями секунды —
            // ключ для последующей продажи (ID_TIME_PLACES).
            $table->string('id_time_places', 32)->index();
            $table->string('datetime_start', 32)->nullable();
            $table->string('datetime_arrival', 32)->nullable();
            $table->string('status', 16)->default('active')->index(); // active|sold|error
            $table->jsonb('raw_response')->nullable();
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reservations');
    }
};
