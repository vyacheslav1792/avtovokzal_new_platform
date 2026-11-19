<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('station_sales_sync_runs', fn (Blueprint $table) => $table->json('refund_days')->nullable());
        Schema::table('station_sales_sync_days', fn (Blueprint $table) => $table->string('refund_signature', 64)->nullable());
    }

    public function down(): void
    {
        Schema::table('station_sales_sync_days', fn (Blueprint $table) => $table->dropColumn('refund_signature'));
        Schema::table('station_sales_sync_runs', fn (Blueprint $table) => $table->dropColumn('refund_days'));
    }
};
