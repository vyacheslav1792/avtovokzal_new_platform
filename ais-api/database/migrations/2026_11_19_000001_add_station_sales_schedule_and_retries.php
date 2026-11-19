<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('station_sales_sync_states', function (Blueprint $table) {
            $table->date('last_scheduled_on')->nullable();
        });
        Schema::table('station_sales_sync_runs', function (Blueprint $table) {
            $table->unsignedSmallInteger('read_failures')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('station_sales_sync_runs', function (Blueprint $table) {
            $table->dropColumn('read_failures');
        });
        Schema::table('station_sales_sync_states', function (Blueprint $table) {
            $table->dropColumn('last_scheduled_on');
        });
    }
};
