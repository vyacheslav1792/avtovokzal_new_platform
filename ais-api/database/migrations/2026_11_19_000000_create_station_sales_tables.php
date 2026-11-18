<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('station_sales_sync_states', function (Blueprint $table) {
            $table->integer('station_code')->primary();
            // Только завершённый сбор всего текущего окна, не пересчёт архива.
            $table->timestampTz('current_updated_at')->nullable();
            $table->date('current_from')->nullable();
            $table->date('current_to')->nullable();
        });

        Schema::create('station_sales_sync_runs', function (Blueprint $table) {
            $table->id();
            $table->integer('station_code')->index();
            $table->string('status', 16)->default('queued')->index();
            $table->boolean('is_current');
            $table->date('date_from');
            $table->date('date_to');
            $table->date('next_day');
            $table->json('cursor')->nullable();
            $table->unsignedInteger('days_done')->default(0);
            $table->unsignedBigInteger('rows_read')->default(0);
            $table->uuid('lease_token')->nullable();
            $table->timestampTz('lease_until')->nullable();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('finished_at')->nullable();
            $table->text('error')->nullable();
            $table->timestampsTz();
        });

        // SALE_ALL: исходный ключ из восьми полей хранится в raw_data и
        // нормализуется в record_key. Новые колонки BUSTER тоже сохраняются.
        foreach (['station_sales', 'station_sales_staging'] as $name) {
            Schema::create($name, function (Blueprint $table) use ($name) {
                if ($name === 'station_sales_staging') {
                    $table->foreignId('run_id')->constrained('station_sales_sync_runs')->cascadeOnDelete();
                    $table->string('record_key', 64);
                    $table->primary(['run_id', 'record_key']);
                } else {
                    $table->string('record_key', 64)->primary();
                }
                $table->integer('source_station');
                $table->integer('awtcod')->nullable();
                $table->integer('awtcodsale');
                $table->date('datesale');
                $table->date('datestart');
                $table->smallInteger('type_oper');
                $table->smallInteger('kkm_ok')->nullable();
                $table->smallInteger('tick_back')->nullable();
                foreach (['price_place', 'price_bag', 'price_pl_isx', 'price_bag_isx'] as $column) {
                    $table->decimal($column, 15, 2)->nullable();
                }
                $table->jsonb('raw_data');
                $table->timestampTz('synced_at');
                $table->index(['source_station', 'datesale']);
                $table->index(['awtcod', 'datesale']);
                $table->index(['awtcod', 'datestart']);
            });
        }

        Schema::create('station_sales_sync_days', function (Blueprint $table) {
            $table->integer('station_code');
            $table->date('sale_date');
            $table->timestampTz('updated_at');
            $table->primary(['station_code', 'sale_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('station_sales_sync_days');
        Schema::dropIfExists('station_sales_staging');
        Schema::dropIfExists('station_sales');
        Schema::dropIfExists('station_sales_sync_runs');
        Schema::dropIfExists('station_sales_sync_states');
    }
};
