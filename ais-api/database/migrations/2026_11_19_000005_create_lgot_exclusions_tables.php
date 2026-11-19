<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /*
         * Списки исключений отчёта «Нулевые билеты» (перенос legacy
         * ais/lgot_prig.php, ais/lgot.php и ais/lgot2.php — трёх почти
         * одинаковых страниц, различавшихся видом рейса). В legacy списки
         * лежали ФАЙЛАМИ рядом с кодом — `ATP_ISKLUCH_PRIG.txt`,
         * `TICKET_ISKLUCH_PRIG.txt` (пригород) и `ATP_ISKLUCH.txt`
         * (межобластные): они не попадали ни в бэкап базы, ни в выкладку,
         * и правились только через сам отчёт на боевом сервере.
         *
         * `kind` — вид рейса (prig | obl | mezh). Списки у видов РАЗНЫЕ:
         * с пригородным перевозчиком договор может быть, а с
         * межобластным — нет, и смешивать их нельзя.
         */

        // АТП, с которыми есть договор: их билеты в расчёт не идут.
        // Сверка по НАЗВАНИЮ (ATPNAME), как в legacy, — список сотрудник
        // правит строками в текстовом поле, кодов АТП он не знает.
        Schema::create('lgot_excluded_atps', function (Blueprint $table) {
            $table->id();
            $table->string('kind', 8);
            $table->string('name', 255);
            // Порядок строк в текстовом поле = порядок, как его набрал
            // сотрудник (в файле legacy он тоже сохранялся).
            $table->unsignedInteger('position')->default(0);
            $table->timestampsTz();

            $table->unique(['kind', 'name']);
        });

        // Отдельные билеты, исключённые из расчёта вручную.
        Schema::create('lgot_excluded_tickets', function (Blueprint $table) {
            $table->id();
            $table->string('kind', 8);
            // Ключ билета — «серия||номер» (DOB_N_TICKET||N_TICKET), тот же,
            // что был в файле legacy: по нему билет и узнаётся в выдаче.
            $table->string('tick_key', 96);
            $table->string('serial', 40)->default('');
            $table->string('number', 40)->default('');
            /*
             * Снимок строки отчёта целиком — как и в файле legacy (там
             * хранился весь объект билета). Нужен, чтобы список исключённых
             * показывал теми же колонками, что и отчёт: билет мог уехать из
             * выбранного периода, и перезапросить его данные уже нечем.
             */
            $table->jsonb('data')->nullable();
            // Чего в legacy не было: кто исключил билет. Список живёт годами,
            // а вопрос «почему этот билет не в расчёте» возникает позже.
            $table->string('added_by', 128)->nullable();
            $table->timestampsTz();

            $table->unique(['kind', 'tick_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lgot_excluded_tickets');
        Schema::dropIfExists('lgot_excluded_atps');
    }
};
