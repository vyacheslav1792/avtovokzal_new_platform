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

    /**
     * Шаг 1 регистрации: данные проверены, код подтверждения ушёл на
     * e-mail. Кабинет создаётся только шагом 2 (confirmRegistration) —
     * кроме регистрации по приглашению, где адрес уже подтверждён
     * ссылкой из письма и кабинет открывается сразу.
     */
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

        if ($result['status'] === 'registered') {
            return response()->json([
                'success' => true,
                'data' => [
                    'status' => 'registered',
                    'token' => $result['token'],
                    'user' => $this->presentUser($result['user']),
                ],
            ], 201);
        }

        return response()->json([
            'success' => true,
            'data' => ['status' => 'code_sent'] + $result['code'],
        ]);
    }

    /** Шаг 2 регистрации: код из письма — и кабинет создан. */
    public function confirmRegistration(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'code' => ['required', 'string', 'max:12'],
        ], [
            'code.required' => 'Введите код из письма.',
        ]);

        $result = $this->auth->confirmRegistration(
            $data['email'],
            $data['code'],
            (string) $request->ip(),
        );

        return response()->json([
            'success' => true,
            'data' => [
                'status' => 'registered',
                'token' => $result['token'],
                'user' => $this->presentUser($result['user']),
            ],
        ], 201);
    }

    /**
     * Прислать код заново — для регистрации или смены пароля. Пауза между
     * письмами и суточный потолок на адрес держит AuthCodeService.
     */
    public function resendCode(Request $request): JsonResponse
    {
        $data = $request->validate([
            'type' => ['required', 'string', 'in:register,password_reset'],
            'email' => ['required', 'email', 'max:255'],
        ]);

        $ip = (string) $request->ip();

        $state = $data['type'] === 'register'
            ? $this->auth->resendRegistrationCode($data['email'], $ip)
            : $this->auth->resendPasswordResetCode($data['email'], $ip);

        return response()->json([
            'success' => true,
            'data' => ['status' => 'code_sent'] + $state,
        ]);
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

    /**
     * Профиль: телефон и имя. E-mail — логин, здесь не меняется.
     *
     * Телефон необязателен: кабинет, заведённый через VK, может остаться
     * вообще без контактов (VK отдаёт номер не всегда), и заставлять его
     * вводить только ради сохранения имени незачем. Для покупки номер
     * по-прежнему обязателен — его спрашивает форма заказа.
     */
    public function updateProfile(Request $request): JsonResponse
    {
        $data = $request->validate([
            'phone' => ['nullable', 'string', 'max:32'],
            'name' => ['nullable', 'string', 'max:100'],
        ]);

        $user = $request->user();
        $input = trim((string) ($data['phone'] ?? ''));
        $phone = $input === '' ? null : Phone::normalize($input);

        if ($input !== '' && ! $phone) {
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

    /**
     * Запрос кода для смены пароля. Ответ одинаков для любого адреса
     * (legacy сообщал «не зарегистрирован» — это раскрывало базу
     * покупателей), поэтому состояние кода отдаётся всегда.
     */
    public function forgotPassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email', 'max:255'],
        ], [
            'email.email' => 'Похоже, в адресе электронной почты опечатка. Проверьте его, пожалуйста.',
        ]);

        $state = $this->auth->requestPasswordReset($data['email'], (string) $request->ip());

        return response()->json([
            'success' => true,
            'data' => ['status' => 'code_sent'] + $state,
            'message' => 'Если такой адрес зарегистрирован, мы отправили на него код для смены пароля.',
        ]);
    }

    /** Новый пароль по коду из письма. */
    public function resetPassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'code' => ['required', 'string', 'max:12'],
            'password' => ['required', 'string'],
        ], [
            'code.required' => 'Введите код из письма.',
            'password.required' => 'Придумайте пароль.',
        ]);

        $user = $this->auth->resetPassword($data['email'], $data['code'], $data['password']);

        // Сразу пускаем в кабинет: адрес только что подтверждён кодом из письма.
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
        // Пароль может быть и «старый» — кабинет переехал со прежнего сайта
        // и с тех пор пароль не менялся (users.legacy_password).
        $hasPassword = $request->user()->hasPassword();

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

        $state = $this->auth->requestEmailConfirmation(
            $request->user(),
            $data['email'],
            (string) $request->ip(),
        );

        return response()->json([
            'success' => true,
            'data' => ['status' => 'code_sent'] + $state,
            'message' => 'Мы отправили код подтверждения на '.$state['email'].'.',
        ]);
    }

    /**
     * Подтверждение e-mail кодом из письма. Требует авторизации: код
     * вводится в том же профиле, где адрес и указывали, а привязать его
     * можно только к своему кабинету.
     */
    public function confirmEmail(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'code' => ['required', 'string', 'max:12'],
        ], [
            'code.required' => 'Введите код из письма.',
        ]);

        $user = $this->auth->confirmEmail($request->user(), $data['email'], $data['code']);

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
     * ему показывается «Задать пароль» вместо смены. Кабинет, переехавший
     * со старого сайта, пароль имеет (пусть пока и в legacy-хэше).
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
            // Пароль со старого сайта тоже считается заданным: профиль
            // должен показывать «Смена пароля», а не «Задать пароль».
            'has_password' => $user->hasPassword(),
            'has_vk' => $user->hasIdentity(UserIdentity::PROVIDER_VK),
        ];
    }
}
