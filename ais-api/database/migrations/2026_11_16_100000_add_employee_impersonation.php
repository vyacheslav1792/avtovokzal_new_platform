<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Работа в АИС от имени другого сотрудника (право
     * `employees.impersonate`).
     *
     * У сессии появляются две колонки: кем она выдана (`impersonated_by`)
     * и до какого времени действует (`expires_at`) — обычные сессии живут
     * по бездействию, а эта строго от выдачи. Журнал отдельной таблицей:
     * сессия при выходе удаляется, а запись «кто и когда работал от имени
     * кого» должна оставаться.
     */
    public function up(): void
    {
        Schema::table('employee_tokens', function (Blueprint $table) {
            $table->unsignedBigInteger('impersonated_by')->nullable()->after('kind');
            $table->timestampTz('expires_at')->nullable()->after('last_used_at');
        });

        Schema::create('employee_impersonations', function (Blueprint $table) {
            $table->id();
            // Кого смотрели и кто смотрел. Сотрудника могут удалить —
            // поэтому логины копируем в саму запись.
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->string('employee_login', 64);
            $table->unsignedBigInteger('admin_id');
            $table->string('admin_login', 64);
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 250)->nullable();
            $table->timestampTz('started_at');
            $table->timestampTz('ended_at')->nullable();

            $table->index(['employee_id', 'started_at']);
            $table->index(['admin_id', 'started_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_impersonations');

        Schema::table('employee_tokens', function (Blueprint $table) {
            $table->dropColumn(['impersonated_by', 'expires_at']);
        });
    }
};
