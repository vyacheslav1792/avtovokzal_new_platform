<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Расписание, заведённое ВРУЧНУЮ — для станций, у которых базы продаж
 * больше нет (станция закрыта, касса снята), а автобусы через них ходят.
 *
 * В legacy для таких станций лежал готовый HTML-файл
 * (`url/raspisanie/<slug>.html`), который правили руками; теперь маршруты
 * хранятся в том же `routes` (jsonb), что и у автоматического расписания,
 * и редактируются в АИС.
 *
 * `is_manual` защищает их от перезаписи: `schedule:sync` такие станции
 * пропускает — иначе первый же прогон (станцию снова включили в
 * справочник, база не ответила) затёр бы ручную работу.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('station_schedules', function (Blueprint $table) {
            $table->boolean('is_manual')->default(false)->after('routes_count');
            // Кто и когда правил: в истории правок расписания это
            // единственный след — вендорная база тут ни при чём.
            $table->string('edited_by', 128)->nullable()->after('is_manual');
            // Пояснение для сотрудников (например «станция закрыта
            // 01.09.2026, расписание ведём вручную»).
            $table->string('note', 255)->nullable()->after('edited_by');
        });
    }

    public function down(): void
    {
        Schema::table('station_schedules', function (Blueprint $table) {
            $table->dropColumn(['is_manual', 'edited_by', 'note']);
        });
    }
};
