<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Настройки сайта, которые сотрудник правит в АИС (страница «Настройки»):
 * таблица `settings` в БД сайта, значение — JSON.
 *
 * Читаем ПРЯМЫМ запросом к БД сайта (соединение `site`, read-only) —
 * тем же, которым пользуются отчёты по продажам сайта.
 *
 * Зачем это здесь: проверка связи со станцией и отчёты по её базе должны
 * ждать столько же, сколько ждёт продажа. Иначе кнопка «Проверить» в
 * справочнике станций показывала бы «связь есть» там, где покупателю её
 * уже не хватает.
 *
 * Если настройки недоступны (таблицы ещё нет, настройку ни разу не
 * меняли), работает значение по умолчанию из config/stations_db.php.
 *
 * Кэш 30 секунд — как у справочника станций.
 */
class SiteSettings
{
    private const CACHE_KEY = 'site-settings:all';

    private const CACHE_TTL = 30;

    /** @var array<string, mixed>|null */
    private ?array $memo = null;

    /**
     * Сколько секунд ждать подключения к базе станции.
     *
     * Границы здесь свои, а не только в каталоге backend: значение могло
     * попасть в базу до появления границ или мимо админки (правка SQL),
     * а ноль означал бы «не ждать вовсе» — тогда ни одна станция не
     * ответила бы.
     */
    public function stationConnectTimeout(): int
    {
        $default = (int) config('stations_db.connect_timeout', 5);
        $value = $this->get('station_connect_timeout');

        if (! is_numeric($value)) {
            return $default;
        }

        return max(1, min(60, (int) $value));
    }

    public function stationSalesLookbackDays(): int
    {
        $value = $this->get('station_sales_lookback_days');

        return is_numeric($value)
            ? max(1, min(3650, (int) $value))
            : (int) config('station_sales.default_lookback_days', 90);
    }

    private function get(string $key): mixed
    {
        return $this->all()[$key] ?? null;
    }

    /** @return array<string, mixed> */
    private function all(): array
    {
        if ($this->memo !== null) {
            return $this->memo;
        }

        try {
            $values = Cache::remember(self::CACHE_KEY, self::CACHE_TTL, fn () => $this->fromDatabase());
        } catch (Throwable) {
            $values = [];
        }

        return $this->memo = $values;
    }

    /** @return array<string, mixed> */
    private function fromDatabase(): array
    {
        $rows = DB::connection('site')->table('settings')->get(['key', 'value']);

        $values = [];

        foreach ($rows as $row) {
            // В таблице лежит JSON: у числа это «5», у выключателя true.
            $values[(string) $row->key] = json_decode((string) $row->value, true);
        }

        return $values;
    }
}
