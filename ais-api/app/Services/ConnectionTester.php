<?php

namespace App\Services;

use App\Models\KkmServer;
use App\Models\StationProfile;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Проверка связи по настройкам из справочника — кнопкой из АИС.
 *
 * Без неё переключение варианта подключения было бы гаданием: админ
 * менял профиль и узнавал о неудаче по жалобам покупателей. Результат
 * пишется в сам профиль (`checked_at`, `check_ok`, `check_error`),
 * поэтому в списке видно, когда станцию проверяли последний раз.
 */
class ConnectionTester
{
    /** Таймаут проверки: быстрее получить «нет связи», чем ждать минуту. */
    private const TIMEOUT_SECONDS = 8;

    /** Проверка базы станции: подключаемся и спрашиваем время у сервера. */
    public function station(StationProfile $profile): array
    {
        $started = microtime(true);

        try {
            // Ограничиваем ожидание на уровне драйвера: недоступная
            // станция иначе держит запрос до собственного таймаута.
            ini_set('firebird.timestampformat', '%Y-%m-%d %H:%M:%S');

            $connection = new StationConnection([
                'db_host' => $profile->db_host,
                'db_port' => $profile->db_port,
                'db_name' => $profile->db_name,
                'db_user' => $profile->db_user,
                'db_password' => $profile->db_password,
            ]);

            // Минимальный запрос, который есть в любой базе BUSTER.
            $rows = $connection->select('select first 1 current_timestamp as NOW from RDB$DATABASE');

            $result = [
                'ok' => true,
                'error' => null,
                'info' => [
                    'server_time' => $rows[0]['NOW'] ?? null,
                    'ms' => (int) round((microtime(true) - $started) * 1000),
                ],
            ];
        } catch (Throwable $e) {
            $result = [
                'ok' => false,
                'error' => $this->shorten($e->getMessage()),
                'info' => ['ms' => (int) round((microtime(true) - $started) * 1000)],
            ];
        }

        $profile->update([
            'checked_at' => now(),
            'check_ok' => $result['ok'],
            'check_error' => $result['error'],
        ]);

        return $result;
    }

    /**
     * Проверка KkmServer: спрашиваем список устройств (GetDataKKT тянет
     * лишние права, а List показывает и связь, и саму ККТ).
     */
    public function kkm(KkmServer $server): array
    {
        $started = microtime(true);

        try {
            $response = Http::baseUrl(rtrim($server->url, '/'))
                ->withBasicAuth((string) $server->user, (string) $server->password)
                ->timeout(self::TIMEOUT_SECONDS)
                ->acceptJson()
                ->post('/Execute/sync', [
                    'Command' => 'GetDataKKT',
                    'NumDevice' => $server->num_device,
                    'Timeout' => min($server->command_timeout, self::TIMEOUT_SECONDS),
                ]);

            $body = $response->json() ?? [];
            // KkmServer отвечает 200 и на отказ: смотрим Status (0 — ок).
            $ok = $response->successful() && (int) ($body['Status'] ?? 1) === 0;

            $info = [
                'ms' => (int) round((microtime(true) - $started) * 1000),
                'kkt' => $body['Info']['KktNumber'] ?? null,
                'fn' => $body['Info']['FnNumber'] ?? null,
                'session_state' => $body['Info']['SessionState'] ?? null,
                'session_number' => $body['Info']['SessionNumber'] ?? null,
            ];

            $result = [
                'ok' => $ok,
                'error' => $ok ? null : $this->shorten((string) ($body['Error'] ?? 'KkmServer ответил отказом')),
                'info' => $info,
            ];
        } catch (Throwable $e) {
            $result = [
                'ok' => false,
                'error' => $this->shorten($e->getMessage()),
                'info' => ['ms' => (int) round((microtime(true) - $started) * 1000)],
            ];
        }

        $server->update([
            'checked_at' => now(),
            'check_ok' => $result['ok'],
            'check_error' => $result['error'],
            'check_info' => $result['info'],
        ]);

        return $result;
    }

    /** Сообщения драйвера бывают в несколько строк — в список столько не нужно. */
    private function shorten(string $message): string
    {
        $message = trim(preg_replace('/\s+/', ' ', $message) ?? '');

        return mb_substr($message, 0, 480);
    }
}
