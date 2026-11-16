<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Возврат, когда база станции недоступна: деньги покупателю отдаём
     * сразу, а аннулирование билета в ведомости станции досылаем позже —
     * когда у станции появится связь (у Кром, например, нет интернета, а
     * покупатели просят деньги).
     *
     * Поэтому у возврата появляется ВТОРОЕ состояние, независимое от
     * денег: аннулирован ли бланк на станции.
     */
    public function up(): void
    {
        Schema::table('refunds', function (Blueprint $table) {
            // Когда бланк аннулирован в базе станции. У обычного возврата —
            // момент возврата, у отложенного — момент досылки.
            $table->timestampTz('station_annulled_at')->nullable()->after('money_refunded_at');
            // Ждёт досылки: деньги вернули, станция ещё не знает.
            $table->boolean('station_annul_pending')->default(false)->after('station_annulled_at');
            $table->timestampTz('station_annul_attempted_at')->nullable()->after('station_annul_pending');
            $table->string('station_annul_error', 500)->nullable()->after('station_annul_attempted_at');
            // Отметка, что дату возврата в журнале станции перенесли на
            // дату фактического возврата денег (настройка в АИС).
            $table->timestampTz('station_backdated_at')->nullable()->after('station_annul_error');

            $table->index(['station_annul_pending', 'created_at']);
        });

        /*
         * Прежние возвраты аннулированы на станции в момент создания —
         * иначе они выглядели бы «ждущими досылки». Отметка о багажном
         * бланке (refunds:annul-baggage, нулевая сумма) тоже относится к
         * уже аннулированным.
         */
        DB::table('refunds')->update(['station_annulled_at' => DB::raw('created_at')]);
    }

    public function down(): void
    {
        Schema::table('refunds', function (Blueprint $table) {
            $table->dropIndex(['station_annul_pending', 'created_at']);
            $table->dropColumn([
                'station_annulled_at', 'station_annul_pending',
                'station_annul_attempted_at', 'station_annul_error', 'station_backdated_at',
            ]);
        });
    }
};
