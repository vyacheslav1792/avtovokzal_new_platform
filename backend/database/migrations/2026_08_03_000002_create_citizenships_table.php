<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Справочник гражданств. Код = COUNTRIES_TABLE.ID в базах продаж
        // (BUSTER), т.к. он передаётся в KS_SALE_PLACES. 185 = Россия.
        Schema::create('citizenships', function (Blueprint $table) {
            $table->unsignedInteger('code')->primary();
            $table->string('name', 128);
            $table->boolean('active')->default(true);
            $table->unsignedSmallInteger('sort')->default(500);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('citizenships');
    }
};
