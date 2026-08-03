<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Разовая правка данных, записанных ДО появления
     * `'timezone' => 'Europe/Moscow'` в config/database.php.
     *
     * Eloquent отдаёт даты строкой без смещения («2026-08-03 22:30:00»), а
     * сессия Postgres была в UTC — московское время попадало в timestamptz
     * как UTC, то есть каждое значение уехало на +3 часа (время прибытия в
     * билете печаталось как 01:30 следующих суток вместо 22:30).
     *
     * Смещение Москвы постоянно (+03 без перехода на летнее время с 2014),
     * поэтому правка — простой сдвиг на 3 часа назад.
     */
    private const COLUMNS = [
        'orders' => ['arrival_at', 'paid_at', 'completed_at', 'created_at', 'updated_at'],
        'tickets' => ['created_at', 'updated_at'],
        'refunds' => ['created_at', 'money_refunded_at', 'updated_at'],
    ];

    public function up(): void
    {
        $this->shift('-');
    }

    public function down(): void
    {
        $this->shift('+');
    }

    private function shift(string $sign): void
    {
        foreach (self::COLUMNS as $table => $columns) {
            $set = collect($columns)
                ->map(fn (string $c) => "$c = $c $sign interval '3 hours'")
                ->implode(', ');

            DB::statement("update $table set $set");
        }
    }
};
