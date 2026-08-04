<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Проверка ответа Yandex SmartCaptcha (замена самописной картинки legacy
 * avtovokzal57/captcha.php). Виджет на сайте отдаёт одноразовый токен,
 * его подлинность подтверждает сервис по серверному ключу.
 *
 * Токен живёт 5 минут и проверяется только один раз.
 */
class SmartCaptcha
{
    /** Настроена ли капча вообще (на локальном контуре ключей нет). */
    public function enabled(): bool
    {
        return (bool) config('captcha.enabled') && (string) config('captcha.server_key') !== '';
    }

    /**
     * Прошёл ли посетитель проверку.
     *
     * При выключенной капче — true. Если сервис не ответил, решает
     * `captcha.fail_open`: обращение с сайта дороже пропущенного спама.
     */
    public function verify(?string $token, ?string $ip = null): bool
    {
        if (! $this->enabled()) {
            return true;
        }

        $token = trim((string) $token);

        if ($token === '') {
            return false;
        }

        try {
            $response = Http::asForm()
                ->timeout((int) config('captcha.timeout'))
                ->post((string) config('captcha.endpoint'), array_filter([
                    'secret' => (string) config('captcha.server_key'),
                    'token' => $token,
                    'ip' => $ip,
                ]));
        } catch (\Throwable $e) {
            Log::warning('SmartCaptcha недоступна', ['error' => $e->getMessage()]);

            return (bool) config('captcha.fail_open');
        }

        if ($response->failed()) {
            Log::warning('SmartCaptcha вернула ошибку', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return (bool) config('captcha.fail_open');
        }

        // {"status": "ok"|"failed", "message": "...", "host": "..."}
        if ($response->json('status') === 'ok') {
            return true;
        }

        Log::info('SmartCaptcha не пропустила посетителя', [
            'message' => $response->json('message'),
            'ip' => $ip,
        ]);

        return false;
    }
}
