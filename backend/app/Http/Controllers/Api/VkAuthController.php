<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AuthService;
use App\Services\VkIdClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Вход через VK ID (OAuth 2.1 + PKCE). В legacy входа через соцсети не было.
 *
 * Ходит сюда не браузер, а сервер Nuxt: он уводит покупателя на VK по
 * адресу из `start`, принимает возврат на /auth/vk/callback и передаёт
 * код сюда же. Так client_id и обмен кода остаются на backend, а фронту
 * достаётся только Bearer-токен кабинета — как при обычном входе.
 */
class VkAuthController extends Controller
{
    public function __construct(
        private readonly VkIdClient $vk,
        private readonly AuthService $auth,
    ) {}

    /** Настроен ли вход через VK: страницы входа и регистрации прячут кнопку. */
    public function providers(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => ['vk' => $this->vk->enabled()],
        ]);
    }

    /** Начало входа: адрес VK ID с code_challenge и одноразовым state. */
    public function start(Request $request): JsonResponse
    {
        $data = $request->validate([
            // Куда вернуть покупателя после входа — адрес внутри сайта.
            // В VK не уходит, лежит в кэше вместе с code_verifier.
            'redirect' => ['nullable', 'string', 'max:512'],
        ]);

        if (! $this->vk->enabled()) {
            return response()->json([
                'success' => false,
                'error' => 'Вход через VK не настроен.',
            ], 404);
        }

        return response()->json(['success' => true, 'data' => $this->vk->start($data['redirect'] ?? null)]);
    }

    /** Возврат от VK: меняем код на токен и впускаем покупателя в кабинет. */
    public function callback(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:512'],
            'state' => ['required', 'string', 'max:128'],
            'device_id' => ['required', 'string', 'max:128'],
        ]);

        try {
            $result = $this->vk->complete($data['code'], $data['state'], $data['device_id']);
        } catch (RuntimeException $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], 422);
        }

        $login = $this->auth->loginWithVk($result['profile'], (string) $request->ip());

        return response()->json([
            'success' => true,
            'data' => [
                'token' => $login['token'],
                'user' => AuthController::present($login['user']),
                'redirect' => $result['redirect'],
                // Новый кабинет без почты — фронт предложит её добавить.
                'ask_email' => $login['created'] && ! $login['user']->email,
            ],
        ]);
    }
}
