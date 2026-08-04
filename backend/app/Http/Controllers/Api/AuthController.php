<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\UserIdentity;
use App\Services\AuthService;
use App\Support\Phone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Регистрация, вход и восстановление пароля личного кабинета
 * (перенос legacy url/register.php, login.php, forgot.php, newpass.php,
 * change.php, logout.php).
 */
class AuthController extends Controller
{
    public function __construct(private readonly AuthService $auth) {}

    /** Регистрация: e-mail + пароль + телефон (необязательно — по приглашению). */
    public function register(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            // Длину и trim проверяет AuthService: сложность сознательно не требуем.
            'password' => ['required', 'string'],
            'phone' => ['required', 'string', 'max:32'],
            'invite_token' => ['nullable', 'string', 'max:128'],
            'agree_pd' => ['accepted'],
        ], [
            'email.email' => 'Похоже, в адресе электронной почты опечатка. Проверьте его, пожалуйста.',
            'email.required' => 'Укажите электронную почту.',
            'password.required' => 'Придумайте пароль.',
            'phone.required' => 'Укажите номер телефона.',
            'agree_pd.accepted' => 'Для регистрации необходимо согласие на обработку персональных данных.',
        ]);

        $result = $this->auth->register($data, (string) $request->ip());

        return response()->json([
            'success' => true,
            'data' => [
                'token' => $result['token'],
                'user' => $this->presentUser($result['user']),
            ],
        ], 201);
    }

    /** Вход по e-mail и паролю. */
    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string'],
        ], [
            'email.required' => 'Укажите электронную почту.',
            'password.required' => 'Введите пароль.',
        ]);

        $result = $this->auth->login($data['email'], $data['password'], (string) $request->ip());

        return response()->json([
            'success' => true,
            'data' => [
                'token' => $result['token'],
                'user' => $this->presentUser($result['user']),
            ],
        ]);
    }

    /** Выход: гасим текущий токен устройства. */
    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()?->delete();

        return response()->json(['success' => true]);
    }

    /** Текущий пользователь (проверка сессии фронтом). */
    public function me(Request $request): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $this->presentUser($request->user()),
        ]);
    }

    /** Профиль: телефон и имя. E-mail — логин, здесь не меняется. */
    public function updateProfile(Request $request): JsonResponse
    {
        $data = $request->validate([
            'phone' => ['required', 'string', 'max:32'],
            'name' => ['nullable', 'string', 'max:100'],
        ], [
            'phone.required' => 'Укажите номер телефона.',
        ]);

        $user = $request->user();
        $phone = Phone::normalize($data['phone']);

        if (! $phone) {
            throw ValidationException::withMessages([
                'phone' => 'Укажите корректный номер телефона, например +7 910 000-00-00',
            ]);
        }

        $user->forceFill([
            'phone' => $phone,
            'name' => $data['name'] ?? $user->name,
            // Номер не подтверждаем (SMS будет позже) — но при смене
            // прежнее подтверждение сбрасываем.
            'phone_verified_at' => $phone === $user->phone ? $user->phone_verified_at : null,
        ])->save();

        return response()->json(['success' => true, 'data' => $this->presentUser($user)]);
    }

    /** Запрос ссылки для сброса пароля (ответ одинаков для любого адреса). */
    public function forgotPassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email', 'max:255'],
        ], [
            'email.email' => 'Похоже, в адресе электронной почты опечатка. Проверьте его, пожалуйста.',
        ]);

        $this->auth->requestPasswordReset($data['email'], (string) $request->ip());

        return response()->json([
            'success' => true,
            'message' => 'Если такой адрес зарегистрирован, мы отправили на него письмо с дальнейшими инструкциями.',
        ]);
    }

    /** Новый пароль по ссылке из письма. */
    public function resetPassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string', 'max:128'],
            'password' => ['required', 'string'],
        ], [
            'password.required' => 'Придумайте пароль.',
        ]);

        $user = $this->auth->resetPassword($data['token'], $data['password']);

        // Сразу пускаем в кабинет: пароль только что подтверждён письмом.
        $login = $this->auth->login($user->email, trim($data['password']), (string) $request->ip());

        return response()->json([
            'success' => true,
            'data' => [
                'token' => $login['token'],
                'user' => $this->presentUser($login['user']),
            ],
        ]);
    }

    /**
     * Смена пароля из кабинета. У кабинета, заведённого через VK, пароля
     * ещё нет — тогда текущий не спрашиваем: покупатель задаёт первый.
     */
    public function changePassword(Request $request): JsonResponse
    {
        $hasPassword = (bool) $request->user()->password;

        $data = $request->validate([
            'current_password' => [$hasPassword ? 'required' : 'nullable', 'string'],
            'password' => ['required', 'string'],
        ], [
            'current_password.required' => 'Введите текущий пароль.',
            'password.required' => 'Придумайте новый пароль.',
        ]);

        $this->auth->changePassword($request->user(), (string) ($data['current_password'] ?? ''), $data['password']);

        return response()->json(['success' => true, 'data' => $this->presentUser($request->user()->refresh())]);
    }

    /**
     * Добавить e-mail кабинету, вошедшему через VK без почты: письмо со
     * ссылкой подтверждения. Сам адрес ставится только по ссылке.
     */
    public function requestEmail(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email', 'max:255'],
        ], [
            'email.required' => 'Укажите электронную почту.',
            'email.email' => 'Похоже, в адресе электронной почты опечатка. Проверьте его, пожалуйста.',
        ]);

        $this->auth->requestEmailConfirmation($request->user(), $data['email'], (string) $request->ip());

        return response()->json([
            'success' => true,
            'message' => 'Мы отправили письмо со ссылкой подтверждения на '.$data['email'].'.',
        ]);
    }

    /**
     * Подтверждение e-mail по ссылке из письма. Без авторизации: письмо
     * могут открыть в другом браузере, а владение кабинетом доказывает
     * сам токен.
     */
    public function confirmEmail(Request $request): JsonResponse
    {
        $data = $request->validate(['token' => ['required', 'string', 'max:128']]);

        $user = $this->auth->confirmEmail($data['token']);

        return response()->json([
            'success' => true,
            'data' => ['email' => $user->email],
        ]);
    }

    /** Проверка ссылки-приглашения: страница регистрации подставит e-mail. */
    public function invite(Request $request): JsonResponse
    {
        $data = $request->validate(['token' => ['required', 'string', 'max:128']]);

        return response()->json([
            'success' => true,
            'data' => ['email' => $this->auth->inviteEmail($data['token'])],
        ]);
    }

    private function presentUser(User $user): array
    {
        return static::present($user);
    }

    /**
     * Пользователь для фронта. `email` может быть null (вход через VK без
     * почты), `has_password` отличает такой кабинет от обычного — в профиле
     * ему показывается «Задать пароль» вместо смены.
     */
    public static function present(User $user): array
    {
        return [
            'id' => $user->id,
            'email' => $user->email,
            'phone' => $user->phone,
            'name' => $user->name,
            'email_verified' => (bool) $user->email_verified_at,
            'phone_verified' => (bool) $user->phone_verified_at,
            'has_password' => (bool) $user->password,
            'has_vk' => $user->hasIdentity(UserIdentity::PROVIDER_VK),
        ];
    }
}
