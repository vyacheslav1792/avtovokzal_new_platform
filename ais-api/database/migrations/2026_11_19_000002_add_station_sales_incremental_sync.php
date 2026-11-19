<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('station_sales_sync_runs', function (Blueprint $table) {
            $table->string('mode')->default('full');
            $table->unsignedInteger('page_size')->default(250); // задания, созданные до миграции
        });
        foreach (['station_sales', 'station_sales_staging'] as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->string('report_hash', 64)->nullable());
        }
        Schema::create('station_sales_sync_seen', function (Blueprint $table) {
            $table->foreignId('run_id')->constrained('station_sales_sync_runs')->cascadeOnDelete();
            $table->string('record_key', 64);
            $table->primary(['run_id', 'record_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('station_sales_sync_seen');
        foreach (['station_sales', 'station_sales_staging'] as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->dropColumn('report_hash'));
        }
        Schema::table('station_sales_sync_runs', fn (Blueprint $table) => $table->dropColumn(['mode', 'page_size']));
    }
};
