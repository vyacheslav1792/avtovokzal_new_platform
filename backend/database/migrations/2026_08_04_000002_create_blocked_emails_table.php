<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Чёрный список адресов (перенос legacy $CHS из
        // avtovokzal57/inc/config.php — там это был массив в исходнике,
        // правка требовала выкладки кода). Закрывает покупку, регистрацию,
        // восстановление пароля и приглашение в кабинет.
        Schema::create('blocked_emails', function (Blueprint $table) {
            $table->id();
            $table->string('email', 255)->unique();   // всегда в нижнем регистре
            $table->string('reason', 255)->nullable();
            $table->timestampsTz();
        });

        // Первичное наполнение из BLOCKED_EMAILS (.env): боевые адреса
        // в git не попадают, а список после миграции живёт в базе.
        $emails = array_values(array_filter(array_map(
            fn (string $email) => mb_strtolower(trim($email)),
            explode(',', (string) env('BLOCKED_EMAILS', '')),
        )));

        foreach ($emails as $email) {
            DB::table('blocked_emails')->insert([
                'email' => $email,
                'reason' => 'Перенесено из legacy $CHS',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('blocked_emails');
    }
};
