<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Обратная связь сайта (перенос legacy avtovokzal57/url/contact.php).
        // В legacy сообщение НИГДЕ не сохранялось: письмо уходило на
        // support_email и всё — если письмо терялось или уезжало в спам,
        // обращение исчезало бесследно. Теперь сообщение хранится здесь,
        // а письмо — лишь уведомление.
        Schema::create('feedback_messages', function (Blueprint $table) {
            $table->id();
            $table->string('topic', 100)->nullable();   // тема обращения (справочник config/feedback.php)
            $table->string('name', 255);
            $table->string('email', 255);
            $table->string('phone', 50)->nullable();
            $table->text('body');

            // new — не разобрано, in_progress — в работе, answered — отвечено,
            // spam — отсеяно антиспамом или оператором.
            $table->string('status', 20)->default('new');
            // Какое правило сработало (чтобы оператор видел причину пометки).
            $table->string('spam_reason', 255)->nullable();

            // Кто написал: авторизованный покупатель — со ссылкой на профиль.
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 500)->nullable();
            // Прошла ли проверка SmartCaptcha (при выключенной капче — null).
            $table->boolean('captcha_passed')->nullable();

            // Работа оператора АИС.
            $table->text('admin_note')->nullable();
            $table->timestampTz('answered_at')->nullable();

            $table->timestampsTz();

            $table->index('created_at');
            $table->index(['status', 'created_at']);
            $table->index('email');
        });

        // Антиспам-словарь. В legacy это были ~60 preg_match подряд прямо в
        // теле url/contact.php: любое новое спам-слово означало правку
        // исходника и выкладку на боевой сервер.
        Schema::create('spam_rules', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('position')->default(0);
            // substring — подстрока без учёта регистра (обычное слово словаря),
            // regex — регулярное выражение (ссылки, шаблоны вида «флакон.*»),
            // latin_dominates — служебное правило legacy: латиницы в тексте
            // больше, чем кириллицы (типичная англоязычная рассылка).
            $table->string('kind', 20)->default('substring');
            // Что проверяем: текст сообщения, имя, адрес или всё сразу.
            $table->string('scope', 20)->default('body'); // body|name|email|any
            // Сам паттерн; у latin_dominates не используется.
            $table->string('pattern', 500)->nullable();
            // Пояснение для оператора: зачем правило заведено.
            $table->string('note', 255)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();

            $table->index(['is_active', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('spam_rules');
        Schema::dropIfExists('feedback_messages');
    }
};
