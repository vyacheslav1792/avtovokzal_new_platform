<?php

namespace App\Services;

use App\Models\Station;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Источник справочника станций для АИС: таблица `stations` в БД ais.
 *
 * Пока таблица пуста (или недоступна), отдаётся прежний файл
 * shared/stations.php — так работает локальный контур и так переживается
 * сбой базы: отчёты не должны обрушиться из-за того, что справочник
 * переехал в базу.
 *
 * Кэш короткий (30 секунд) — ровно чтобы переключение варианта
 * подключения из АИС доезжало до сервисов «почти сразу», но каждый
 * запрос не ходил в базу за одними и теми же двумя десятками строк.
 */
class StationCatalog
{
    private const CACHE_KEY = 'stations:registry';

    private const CACHE_TTL = 30;

    /** @var array<string, array>|null */
    private ?array $memo = null;

    /**
     * Станции в формате прежнего конфига: код => поля.
     * Отключённые (`is_active = false`) не попадают сюда вовсе — для
     * сервисов такой станции просто нет.
     *
     * @return array<string, array>
     */
    public function entries(): array
    {
        if ($this->memo !== null) {
            return $this->memo;
        }

        try {
            $entries = Cache::remember(self::CACHE_KEY, self::CACHE_TTL, fn () => $this->fromDatabase());
        } catch (Throwable) {
            // База АИС недоступна — работаем по файлу, как раньше.
            $entries = [];
        }

        return $this->memo = $entries ?: (array) config('stations.list', []);
    }

    /** Сбросить кэш — вызывается после правки справочника в АИС. */
    public function forget(): void
    {
        $this->memo = null;
        Cache::forget(self::CACHE_KEY);
    }

    /** @return array<string, array> */
    private function fromDatabase(): array
    {
        $entries = [];

        $stations = Station::query()
            ->with('profiles')
            ->where('is_active', true)
            ->orderBy('inlist')
            ->get();

        foreach ($stations as $station) {
            $entry = $station->toRegistryEntry();

            // Станция без настроенного подключения для сервисов не
            // существует: к ней всё равно некуда обращаться.
            if ($entry !== null) {
                $entries[(string) $station->awtcod] = $entry;
            }
        }

        return $entries;
    }
}
