<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Чеки ККМ. external_id — ключ идемпотентности клиента
        // (повторный запрос с тем же ключом не пробьёт второй чек),
        // id_command — IdCommand той же операции внутри KkmServer.
        Schema::create('receipts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('client_id', 64);
            $table->string('external_id', 128)->unique();
            $table->string('type', 16);                    // sale | refund
            $table->uuid('id_command')->unique();
            $table->unsignedSmallInteger('num_device');

            $table->string('email', 255);
            $table->jsonb('items');
            $table->decimal('electronic_payment', 12, 2);

            // pending → registered | error
            $table->string('status', 16)->default('pending')->index();
            $table->unsignedInteger('check_number')->nullable();
            $table->unsignedInteger('session_number')->nullable();
            $table->string('fiscal_sign', 64)->nullable(); // ФП (fp из QR-кода)
            $table->string('ofd_url', 1000)->nullable();
            $table->string('qr_code', 1000)->nullable();
            $table->jsonb('raw_response')->nullable();
            $table->string('error', 1000)->nullable();
            $table->timestampTz('registered_at')->nullable();

            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('receipts');
    }
};
