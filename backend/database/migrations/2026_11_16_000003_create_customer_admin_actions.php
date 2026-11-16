<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Журнал того, что сотрудники АИС делают с кабинетами покупателей:
     * сброс пароля, подтверждение адреса, завершение сессий, отвязка VK,
     * смена e-mail и вход в кабинет от имени покупателя.
     *
     * Нужен именно журнал, а не отметка в самом кабинете: действия
     * затрагивают чужие персональные данные и чужой доступ, и вопрос
     * «кто сменил адрес у этого кабинета» должен иметь ответ. Просмотры
     * не пишем — иначе журнал заполнится листанием списка.
     */
    public function up(): void
    {
        Schema::create('customer_admin_actions', function (Blueprint $table) {
            $table->id();

            // Кабинет может быть и удалён — тогда остаётся адрес, по
            // которому действие можно соотнести с заказами.
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('email', 255)->nullable();

            /*
             * Сотрудник живёт в ДРУГОЙ базе (`ais`), поэтому внешнего ключа
             * здесь быть не может — храним id и логин с ФИО на момент
             * действия: сотрудника потом переименуют или удалят, а запись
             * журнала должна остаться читаемой.
             */
            $table->unsignedBigInteger('employee_id');
            $table->string('employee_login', 64);
            $table->string('employee_name', 128)->nullable();

            $table->string('action', 32);
            $table->jsonb('details')->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestampTz('created_at')->nullable();

            $table->index(['user_id', 'created_at']);
            $table->index('created_at');
            $table->index(['employee_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_admin_actions');
    }
};
