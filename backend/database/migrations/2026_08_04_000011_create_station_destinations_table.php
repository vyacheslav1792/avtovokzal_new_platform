<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Пункты назначения станции (список «Куда» на форме поиска).
        // Раньше читались из Firebird на КАЖДЫЙ запрос: процедура
        // KS_STATIONS отдаёт две сотни строк, а список меняется раз в
        // месяц. Теперь лежат здесь, обновляются по расписанию (ofelia,
        // 07:00 и 10:00 МСК) и кнопкой в АИС.
        Schema::create('station_destinations', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('awtcod')->unique();
            $table->string('station_name', 128);

            // Уже отсортированный список: [{name, pre_sale_days}, …].
            // Порядок задаёт StationNameSorter в sales-api — сортировать
            // заново на каждом запросе незачем.
            $table->jsonb('destinations');
            $table->unsignedSmallInteger('destinations_count')->default(0);

            // Когда данные сняты со станции и когда обновление выполнялось.
            // При ошибке остаётся ПРОШЛЫЙ список и текст ошибки: пустой
            // список «Куда» означает, что покупатель не может даже начать поиск.
            $table->timestampTz('generated_at')->nullable();
            $table->timestampTz('synced_at')->nullable();
            $table->text('sync_error')->nullable();

            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('station_destinations');
    }
};
