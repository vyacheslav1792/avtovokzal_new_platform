<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('station_sales_sync_runs', function (Blueprint $table) {
            $table->unsignedSmallInteger('chunk_days')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('station_sales_sync_runs', function (Blueprint $table) {
            $table->dropColumn('chunk_days');
        });
    }
};
