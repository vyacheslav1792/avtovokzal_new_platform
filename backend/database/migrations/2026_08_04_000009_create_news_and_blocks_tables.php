<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Новости и объявления сайта. В legacy раздела новостей не было:
        // объявления («День поля», заказной рейс до Спасского-Лутовинова)
        // лежали вперемешку с вёрсткой в avtovokzal57/vidjets/news.php и
        // показывались по условию time() < strtotime('2026-07-06') — каждая
        // новость означала правку исходника и выкладку на боевой сайт.
        Schema::create('news', function (Blueprint $table) {
            $table->id();
            // Адрес новости на сайте: /news/<slug>.
            $table->string('slug', 120)->unique();
            // Заголовок: он же H1 страницы и подпись в списке.
            $table->string('title', 255);
            // <title> и description. Пустые — берём title и краткое
            // содержание: заставлять заполнять их ради каждого объявления
            // значит получить их незаполненными.
            $table->string('meta_title', 255)->nullable();
            $table->string('meta_description', 500)->nullable();
            // Краткое содержание (HTML) — для списка и для пересылки
            // в мессенджерах (og:description).
            $table->text('lead')->nullable();
            $table->text('content');
            // Обложка — адрес файла сайта (/upload/…), её же отдаём как
            // og:image. Отдельной таблицы картинок не держим: обложка одна.
            $table->string('cover_url', 500)->nullable();
            $table->string('cover_alt', 255)->nullable();
            // Дата, показываемая в новости (legacy NEWDATE форка).
            $table->timestampTz('published_at');
            // Срок показа: объявление само исчезает, как в legacy по
            // time() < strtotime(…). Пусто — показывать бессрочно.
            $table->timestampTz('publish_from')->nullable();
            $table->timestampTz('publish_to')->nullable();
            // Выключенная новость недоступна и по прямой ссылке (404), но
            // остаётся в базе: так в legacy жили закомментированные блоки.
            $table->boolean('is_active')->default(true);
            // Показывать в блоке последних новостей на главной и на
            // стартовом экране приложения.
            $table->boolean('on_index')->default(false);
            $table->timestampsTz();

            $table->index(['is_active', 'published_at']);
            $table->index(['on_index', 'published_at']);
        });

        // Блоки сайта («виджеты» форка): редактируемые куски HTML в заранее
        // отведённых местах страниц. В legacy это были PHP-файлы
        // avtovokzal57/vidjets/*.php (баннер над формой поиска, приветствие,
        // памятки на главной) плюс куски, вбитые прямо в url/index.php —
        // приветствие и обращение правились выкладкой кода на бой.
        //
        // Ключ блока — слот из config/site_blocks.php: произвольных ключей
        // не бывает, сайт выводит блок только там, где для него есть место.
        Schema::create('site_blocks', function (Blueprint $table) {
            $table->id();
            $table->string('key', 64)->unique();
            $table->text('content');
            $table->boolean('is_active')->default(true);
            // Срок показа — как у новости: баннер мероприятия выключается
            // сам, без правки блока.
            $table->timestampTz('publish_from')->nullable();
            $table->timestampTz('publish_to')->nullable();
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_blocks');
        Schema::dropIfExists('news');
    }
};
