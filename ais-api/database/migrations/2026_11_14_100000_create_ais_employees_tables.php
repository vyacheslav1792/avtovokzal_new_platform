<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Сотрудники АИС, роли и сессии. В legacy АИС авторизации не было вовсе
 * (доступ закрывался ограничением по IP на nginx), поэтому переносить нечего —
 * схема новая.
 *
 * Живёт в БД `ais`; backend читает отсюда же сотрудника и его права при
 * проверке своих /api/v1/admin/* (соединение `ais` в его config/database.php).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            // Системная роль «Администратор»: права не хранит и не
            // редактируется — у неё по определению есть всё.
            $table->boolean('is_system')->default(false);
            $table->string('description', 255)->default('');
            // Список прав вида "<модуль>.<право>". Редактор роли заменяет
            // набор целиком, поэтому отдельная таблица связей не нужна.
            $table->jsonb('permissions')->default('[]');
            $table->timestamps();
        });

        Schema::create('employees', function (Blueprint $table) {
            $table->id();
            // Логин как ввели (его и показываем), и он же в нижнем регистре
            // для поиска: вход нечувствителен к регистру, буквы любые —
            // в том числе русские.
            $table->string('login', 150);
            $table->string('login_normalized', 150)->unique();
            $table->string('full_name', 150)->default('');
            $table->string('position', 150)->default('');
            $table->string('email', 255)->nullable();
            $table->string('phone', 32)->default('');
            $table->string('password');
            // Код подтверждения опасных действий — только хэш: его
            // проверяют, но никому не показывают.
            $table->string('confirm_code')->nullable();
            // Токен быстрого входа передаётся в адресе, поэтому нужен и
            // поиск по нему (sha256), и возможность показать владельцу
            // сам токен (значение в шифре Laravel, не открытым текстом).
            $table->text('fast_token')->nullable();
            $table->string('fast_token_hash', 64)->nullable()->unique();
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_login_at')->nullable();
            $table->string('last_login_ip', 45)->default('');
            $table->timestamps();
        });

        Schema::create('employee_role', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->foreignId('role_id')->constrained('roles')->cascadeOnDelete();
            $table->unique(['employee_id', 'role_id']);
        });

        Schema::create('employee_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            // В базе только sha256 — украденный дамп не даёт войти.
            $table->string('token_hash', 64)->unique();
            // Как вошли: пароль или токен быстрого входа (видно сотруднику
            // в списке своих сессий).
            $table->string('kind', 16)->default('password');
            $table->string('ip', 45)->default('');
            $table->string('user_agent', 255)->default('');
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();

            $table->index('last_used_at');
        });

        // Системная роль нужна системе, а не данным: на неё опирается
        // проверка прав (администратору доступно всё, включая модули,
        // которых ещё нет), и заводить её сидом нельзя — без неё первый
        // `ais:admin` остался бы без роли.
        DB::table('roles')->insert([
            'name' => 'Администратор',
            'is_system' => true,
            'description' => 'Полный доступ ко всем модулям АИС',
            'permissions' => '[]',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_tokens');
        Schema::dropIfExists('employee_role');
        Schema::dropIfExists('employees');
        Schema::dropIfExists('roles');
    }
};
