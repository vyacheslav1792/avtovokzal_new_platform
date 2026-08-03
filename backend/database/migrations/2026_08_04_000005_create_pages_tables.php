<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Обычные (текстовые) страницы сайта: оплата, документы, FAQ,
        // соглашение и политика ПД, услуги, 223-ФЗ, противодействие
        // коррупции и мошенничеству. В legacy каждая была отдельным
        // PHP-файлом avtovokzal57/url/<slug>.php, а <title>/description —
        // ветками switch в index.php: правка текста означала выкладку кода.
        Schema::create('pages', function (Blueprint $table) {
            $table->id();
            // Адрес страницы на сайте: /payways, /faq, … (как в legacy).
            $table->string('slug', 100)->unique();
            // Название для админки и подстановки в новый пункт меню.
            $table->string('title', 255);
            // <title> и description страницы (в legacy — switch в index.php).
            $table->string('meta_title', 255);
            $table->string('meta_description', 500)->nullable();
            // HTML страницы целиком, как его правил бы верстальщик.
            $table->text('content');
            // Выключенная страница отдаёт 404, но остаётся в базе — так в
            // legacy жили закомментированные пункты меню.
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
        });

        // Меню сайта: шапка (area=header) и подвал (area=footer).
        // В legacy оба списка были захардкожены в index.php, причём в шапке
        // часть ссылок пряталась в выпадающий раздел «Пассажирам» — его
        // роль играет group.
        Schema::create('menu_items', function (Blueprint $table) {
            $table->id();
            $table->string('area', 16);                  // header|footer
            $table->string('title', 255);                // текст ссылки
            $table->string('url', 500);                  // /payways, /faq, …
            $table->string('group', 255)->nullable();    // «Пассажирам» (только header)
            $table->unsignedInteger('position')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();

            $table->index(['area', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('menu_items');
        Schema::dropIfExists('pages');
    }
};
