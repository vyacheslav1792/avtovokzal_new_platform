<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * Настройки подключения к KkmServer. Источник — справочник в БД АИС
 * (таблица `kkm_servers`, правится сотрудником в АИС); до 15.11.2026 это
 * был только `config/kkm.php` и переменные окружения, то есть правка
 * файла и пересоздание контейнера.
 *
 * Действует вариант с `is_active`. Если справочник заполнен, но активного
 * варианта нет — **фискализация выключена сознательно**, и чек не
 * пробивается: лучше отказ с понятной причиной, чем чек «куда-нибудь».
 *
 * Пока справочник ПУСТ (или база АИС недоступна) — работает прежний
 * config/kkm.php: так живёт локальный контур, и так переносится сбой БД.
 *
 * Кэш 30 секунд: переключение из АИС доезжает почти сразу, но каждый чек
 * не ходит в базу за одной строкой.
 */
class KkmSettings
{
    private const CACHE_KEY = 'kkm:active-server';

    private const CACHE_TTL = 30;

    /** @var array<string, mixed>|null */
    private ?array $memo = null;

    /** Выключена ли фискализация справочником (есть записи, активной нет). */
    private bool $disabled = false;

    public function url(): string
    {
        return (string) $this->get('url');
    }

    public function user(): string
    {
        return (string) $this->get('user');
    }

    public function password(): string
    {
        return (string) $this->get('password');
    }

    public function numDevice(): int
    {
        return (int) $this->get('num_device');
    }

    public function commandTimeout(): int
    {
        return (int) $this->get('command_timeout');
    }

    public function cashierName(): string
    {
        return (string) $this->get('cashier_name');
    }

    /** Название действующего варианта — для журнала и ответов API. */
    public function label(): ?string
    {
        $settings = $this->settings();

        return $settings['label'] ?? null;
    }

    private function get(string $key): mixed
    {
        return $this->settings()[$key] ?? null;
    }

    /** @return array<string, mixed> */
    private function settings(): array
    {
        if ($this->memo !== null) {
            $this->assertEnabled();

            return $this->memo;
        }

        try {
            $row = Cache::remember(self::CACHE_KEY, self::CACHE_TTL, function () {
                $active = DB::connection('ais')->table('kkm_servers')
                    ->where('is_active', true)
                    ->orderBy('slot')
                    ->first();

                if ($active) {
                    return (array) $active;
                }

                // Отличаем «справочник не заполнен» от «все варианты
                // выключены»: во втором случае чеки пробивать нельзя.
                return DB::connection('ais')->table('kkm_servers')->exists()
                    ? ['__disabled' => true]
                    : [];
            });
        } catch (Throwable) {
            $row = [];
        }

        if (($row['__disabled'] ?? false) === true) {
            $this->disabled = true;
            $this->memo = [];
            $this->assertEnabled();
        }

        $this->memo = $row !== []
            ? [
                'label' => $row['label'] ?? null,
                'url' => $row['url'],
                'user' => (string) ($row['user'] ?? ''),
                'password' => (string) ($row['password'] ?? ''),
                'num_device' => (int) ($row['num_device'] ?? 1),
                'command_timeout' => (int) ($row['command_timeout'] ?? 30),
                'cashier_name' => (string) ($row['cashier_name'] ?? ''),
            ]
            : [
                'label' => null,
                'url' => (string) config('kkm.url'),
                'user' => (string) config('kkm.user'),
                'password' => (string) config('kkm.password'),
                'num_device' => (int) config('kkm.num_device'),
                'command_timeout' => (int) config('kkm.command_timeout'),
                'cashier_name' => (string) config('kkm.cashier_name'),
            ];

        return $this->memo;
    }

    private function assertEnabled(): void
    {
        if ($this->disabled) {
            throw new RuntimeException(
                'Фискализация выключена в АИС: ни один вариант подключения к ККТ не выбран.',
            );
        }
    }
}
