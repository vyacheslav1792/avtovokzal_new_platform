<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Настройки сайта, которые сотрудник правит в АИС (страница «Настройки»):
 * таблица `settings` в БД сайта, значение — JSON.
 *
 * Читаем ПРЯМЫМ запросом к БД сайта, как и справочник станций
 * (StationCatalog): спрашивать backend по сети на каждом запросе продаж
 * незачем — он в этот момент сам ждёт нашего ответа.
 *
 * Если настройки недоступны (база сайта не поднята, таблицы ещё нет,
 * настройку ни разу не меняли), работает значение по умолчанию из
 * config/buster.php: продажа не должна остановиться из-за того, что
 * мы не смогли прочитать настройку.
 *
 * Кэш 30 секунд — как у справочника станций: правка из АИС доезжает почти
 * сразу, но каждый запрос не ходит в базу за одной строкой.
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
        $default = (int) config('buster.station_connect_timeout', 5);
        $value = $this->get('station_connect_timeout');

        if (! is_numeric($value)) {
            return $default;
        }

        return max(1, min(60, (int) $value));
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
