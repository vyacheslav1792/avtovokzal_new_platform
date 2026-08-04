<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /*
         * Сохранённые пассажиры кабинета. Покупают обычно на себя и на одних
         * и тех же людей, а вводить ФИО с документом каждый раз долго и
         * ошибочно — храним их в профиле и подставляем в форму покупки.
         *
         * Состав полей — ровно тот, что уходит в билет (см. OrderService):
         * длины ФИО как в базе станции (фамилия 20, имя/отчество 14),
         * документ разложен на серию и номер правилами KS_CONTROL_DATA_PAS.
         * Ничего сверх того, что и так лежит в заказах (`tickets`), здесь
         * не появляется.
         */
        Schema::create('saved_passengers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('last_name', 20);
            $table->string('first_name', 14);
            $table->string('middle_name', 14)->nullable();
            $table->date('birth_date');
            $table->string('gender', 1)->default('М');
            $table->smallInteger('doc_type')->default(21);
            $table->string('doc_series', 10)->nullable();
            $table->string('doc_number', 25);
            $table->integer('citizenship')->default(185);
            // Последняя покупка с этим пассажиром: список сортируется по ней,
            // чтобы часто ездящие были сверху.
            $table->timestampTz('last_used_at')->nullable();
            $table->timestampsTz();

            // Один и тот же документ у покупателя — одна запись: повторное
            // сохранение при покупке обновляет её, а не плодит дубли.
            $table->unique(['user_id', 'doc_type', 'doc_number']);
            $table->index(['user_id', 'last_used_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('saved_passengers');
    }
};
