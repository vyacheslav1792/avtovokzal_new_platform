<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * VK ID (OAuth 2.1 + PKCE) без JS-SDK.
 *
 * Порядок: start() уводит покупателя на id.vk.ru/authorize, VK возвращает
 * его на redirect_uri с `code`, `state` и `device_id`; exchange() меняет код
 * на токен, profile() забирает данные пользователя.
 *
 * PKCE обязателен: code_verifier (43–128 символов) генерируем здесь, наружу
 * уходит только его SHA-256 (code_challenge). Проверять возврат больше нечем —
 * backend отвечает без сессии, поэтому verifier и адрес возврата лежат в кэше
 * по ключу `state` со сроком жизни vk.state_ttl_minutes.
 */
class VkIdClient
{
    private const CACHE_PREFIX = 'vk:auth:';

    public function enabled(): bool
    {
        return (bool) config('vk.enabled') && (string) config('vk.client_id') !== '';
    }

    /**
     * Начать авторизацию: вернуть адрес VK ID и запомнить состояние.
     *
     * @param  string|null  $redirect  внутренний адрес сайта, куда вернуть
     *                                 покупателя после входа (в VK не уходит)
     * @return array{url: string, state: string}
     */
    public function start(?string $redirect = null): array
    {
        $this->assertEnabled();

        // VK требует state длиной не меньше 32 символов [a-zA-Z0-9_-].
        $state = Str::random(48);
        $verifier = Str::random(64);

        Cache::put(
            self::CACHE_PREFIX.$state,
            ['code_verifier' => $verifier, 'redirect' => $redirect],
            now()->addMinutes((int) config('vk.state_ttl_minutes')),
        );

        // RFC3986: пробелы в scope уходят как %20, а не «+» — в адресе
        // запроса (не в теле формы) это единственная верная кодировка.
        $query = http_build_query([
            'response_type' => 'code',
            'client_id' => (string) config('vk.client_id'),
            'redirect_uri' => (string) config('vk.redirect_uri'),
            'state' => $state,
            'code_challenge' => $this->challenge($verifier),
            'code_challenge_method' => 'S256',
            'scope' => (string) config('vk.scope'),
        ], '', '&', PHP_QUERY_RFC3986);

        return ['url' => config('vk.authorize_url').'?'.$query, 'state' => $state];
    }

    /**
     * Обменять код на токен и забрать профиль.
     *
     * @return array{profile: array, redirect: ?string}
     *
     * @throws RuntimeException при отказе VK ID или просроченном state
     */
    public function complete(string $code, string $state, string $deviceId): array
    {
        $this->assertEnabled();

        $saved = Cache::pull(self::CACHE_PREFIX.$state);

        // state одноразовый: pull убирает его сразу, повтор ссылки не пройдёт.
        if (! is_array($saved)) {
            throw new RuntimeException('Ссылка входа через VK устарела. Попробуйте войти заново.');
        }

        $token = $this->exchange($code, $state, $deviceId, (string) $saved['code_verifier']);
        $profile = $this->profile((string) $token['access_token']);

        // Токен VK нам больше не нужен: билеты и профиль хранит наш кабинет,
        // ходить в VK от имени покупателя мы не собираемся.
        $this->logout((string) $token['access_token']);

        return ['profile' => $profile, 'redirect' => $saved['redirect'] ?? null];
    }

    /**
     * POST /oauth2/auth — обмен кода авторизации на токен.
     * client_secret не участвует: его заменяет PKCE (code_verifier).
     */
    private function exchange(string $code, string $state, string $deviceId, string $verifier): array
    {
        $response = Http::asForm()
            ->timeout((int) config('vk.timeout'))
            ->post((string) config('vk.token_url'), [
                'grant_type' => 'authorization_code',
                'code' => $code,
                'code_verifier' => $verifier,
                'client_id' => (string) config('vk.client_id'),
                'device_id' => $deviceId,
                'redirect_uri' => (string) config('vk.redirect_uri'),
                'state' => $state,
            ]);

        $data = $response->json();

        if (! $response->successful() || ! is_array($data) || empty($data['access_token'])) {
            $this->fail('обмен кода на токен', $data, $response->status());
        }

        return $data;
    }

    /**
     * POST /oauth2/user_info — данные покупателя. `email` и `phone` приходят,
     * только если покупатель дал соответствующие права; остальные поля есть
     * всегда при scope vkid.personal_info.
     *
     * @return array{user_id: string, first_name: ?string, last_name: ?string, email: ?string, phone: ?string, avatar: ?string}
     */
    private function profile(string $accessToken): array
    {
        $response = Http::asForm()
            ->timeout((int) config('vk.timeout'))
            ->post((string) config('vk.user_info_url'), [
                'access_token' => $accessToken,
                'client_id' => (string) config('vk.client_id'),
            ]);

        $user = $response->json('user');

        if (! $response->successful() || ! is_array($user) || empty($user['user_id'])) {
            $this->fail('получение профиля', $response->json(), $response->status());
        }

        return [
            'user_id' => (string) $user['user_id'],
            'first_name' => $this->text($user['first_name'] ?? null),
            'last_name' => $this->text($user['last_name'] ?? null),
            'email' => $this->text($user['email'] ?? null),
            'phone' => $this->text($user['phone'] ?? null),
            'avatar' => $this->text($user['avatar'] ?? null),
        ];
    }

    /** POST /oauth2/logout — гасим выданный нам токен. Не критично, если не вышло. */
    private function logout(string $accessToken): void
    {
        try {
            Http::asForm()
                ->timeout((int) config('vk.timeout'))
                ->post((string) config('vk.logout_url'), [
                    'access_token' => $accessToken,
                    'client_id' => (string) config('vk.client_id'),
                ]);
        } catch (\Throwable $e) {
            Log::warning('VK ID: не удалось погасить токен', ['error' => $e->getMessage()]);
        }
    }

    /** BASE64URL(SHA256(verifier)) — code_challenge по RFC 7636. */
    private function challenge(string $verifier): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    }

    private function text(mixed $value): ?string
    {
        $value = is_scalar($value) ? trim((string) $value) : '';

        return $value === '' ? null : $value;
    }

    private function assertEnabled(): void
    {
        if (! $this->enabled()) {
            throw new RuntimeException('Вход через VK не настроен.');
        }
    }

    /** Ответ VK пишем в лог целиком: без него разбирать отказы нечем. */
    private function fail(string $stage, mixed $payload, int $status): never
    {
        Log::error("VK ID: {$stage} не удался", ['status' => $status, 'response' => $payload]);

        $description = is_array($payload)
            ? ($payload['error_description'] ?? $payload['error_msg'] ?? $payload['error'] ?? null)
            : null;

        throw new RuntimeException(
            'Не удалось войти через VK'.($description ? ': '.$description : '.'),
        );
    }
}
