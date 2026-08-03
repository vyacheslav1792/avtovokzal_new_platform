<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Журнал всех обращений к API продаж: что спросили и что ответили.
        Schema::create('api_requests', function (Blueprint $table) {
            $table->id();
            $table->string('agent_id', 64)->nullable()->index();
            $table->string('method', 10);
            $table->string('path', 255);
            $table->string('action', 64)->nullable()->index();
            $table->string('ip', 45);
            $table->jsonb('request')->nullable();
            $table->jsonb('response')->nullable();
            $table->unsignedSmallInteger('status_code');
            $table->boolean('success')->default(false);
            $table->string('error', 1000)->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->timestampTz('created_at')->useCurrent()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_requests');
    }
};
