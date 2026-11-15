<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Настройки сайта, которые сотрудник меняет из АИС: продажа и
        // возврат билетов на сайте. В legacy «выключить продажу» означало
        // правку кода или снятие сайта целиком — отдельного выключателя не
        // было, и при неполадках у станции покупатель платил за билет,
        // который не оформится.
        //
        // Список настроек задаёт config/settings.php: строка с незнакомым
        // ключом ни на что не влияет.
        Schema::create('settings', function (Blueprint $table) {
            $table->string('key', 64)->primary();
            // Значение любого типа — храним как JSON, чтобы следующая
            // настройка не требовала миграции.
            $table->jsonb('value');
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settings');
    }
};
