<?php

namespace App\Http\Controllers\Api;

use App\Models\Employee;
use App\Models\EmployeeToken;
use App\Rules\StrongPassword;
use App\Services\EmployeeAuth;
use App\Services\EmployeePresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Вход сотрудника в АИС и его собственные настройки.
 *
 * В legacy АИС авторизации не было: доступ закрывался ограничением по IP
 * на nginx, и внутри сети мог войти кто угодно с любой машины.
 */
class AuthController extends Controller
{
    public function __construct(
        private readonly EmployeeAuth $auth,
        private readonly EmployeePresenter $presenter,
    ) {}

    /** Вход по логину и паролю. */
    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'login' => ['required', 'string', 'max:150'],
            'password' => ['required', 'string'],
        ], [
            'login.required' => 'Укажите логин.',
            'password.required' => 'Введите пароль.',
        ]);

        $result = $this->auth->login($data['login'], $data['password'], $request);

        return $this->session($result, $request);
    }

    /**
     * Вход по токену быстрого входа. SPA вызывает его, увидев `?token=`
     * в адресе: токен обменивается на обычную сессию, после чего его можно
     * убрать из адресной строки, чтобы не остался в истории браузера.
     */
    public function tokenLogin(Request $request): JsonResponse
    {
        $token = (string) ($request->input('token') ?? $request->query('token') ?? '');

        if (trim($token) === '') {
            throw ValidationException::withMessages(['token' => 'Токен не передан.']);
        }

        return $this->session($this->auth->loginByToken($token, $request), $request);
    }

    /** Кто вошёл, что ему доступно. */
    public function me(Request $request): JsonResponse
    {
        /** @var Employee $employee */
        $employee = $request->user();
        $session = $employee->currentSession();

        return response()->json([
            'ok' => true,
            'employee' => $this->presenter->self($employee),
            /*
             * Работа от имени сотрудника: SPA показывает полосу «вы
             * работаете как …» с кнопкой возврата и скрывает кнопки
             * изменений — менять что-либо в этом режиме сервер всё равно
             * не даст (middleware employee.not-impersonated).
             */
            'impersonated' => $employee->isImpersonated(),
            'impersonated_by' => $session?->isImpersonated()
                ? Employee::find($session->impersonated_by)?->login
                : null,
            'impersonation_expires_at' => $session?->expires_at?->toIso8601String(),
        ]);
    }

    /** Выход: гасим сессию и cookie. */
    public function logout(Request $request): JsonResponse
    {
        $this->auth->logout($request);

        return response()->json(['ok' => true])->withCookie($this->auth->forgetCookie());
    }

    /** Свои сведения: ФИО, должность, контакты. Логин меняет администратор. */
    public function updateProfile(Request $request): JsonResponse
    {
        /** @var Employee $employee */
        $employee = $request->user();

        $data = $request->validate([
            'full_name' => ['nullable', 'string', 'max:150'],
            'position' => ['nullable', 'string', 'max:150'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:32'],
        ]);

        $employee->fill([
            'full_name' => trim((string) ($data['full_name'] ?? '')),
            'position' => trim((string) ($data['position'] ?? '')),
            'email' => trim((string) ($data['email'] ?? '')) ?: null,
            'phone' => trim((string) ($data['phone'] ?? '')),
        ])->save();

        return response()->json([
            'ok' => true,
            'employee' => $this->presenter->self($employee),
        ]);
    }

    /** Смена своего пароля. */
    public function changePassword(Request $request): JsonResponse
    {
        /** @var Employee $employee */
        $employee = $request->user();

        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', new StrongPassword([
                $employee->login, $employee->full_name, $employee->email,
            ])],
        ], [
            'current_password.required' => 'Введите текущий пароль.',
            'password.required' => 'Придумайте новый пароль.',
        ]);

        $this->assertCurrentPassword($employee, $data['current_password']);

        $employee->password = trim($data['password']);
        $employee->save();

        // Остальные сессии гасим: пароль меняют и когда он мог утечь.
        $employee->tokens()
            ->where('token_hash', '!=', EmployeeToken::hash((string) $this->auth->sessionTokenFromRequest($request)))
            ->delete();

        return response()->json(['ok' => true]);
    }

    /** Смена своего кода подтверждения опасных действий (3–20 любых знаков). */
    public function changeConfirmCode(Request $request): JsonResponse
    {
        /** @var Employee $employee */
        $employee = $request->user();

        $data = $request->validate([
            'current_password' => ['required', 'string'],
            // Состав знаков не проверяем: это подтверждение «это точно я»,
            // а не второй пароль.
            'code' => [
                'required', 'string',
                'min:'.config('ais_auth.confirm_code.min'),
                'max:'.config('ais_auth.confirm_code.max'),
            ],
        ], [
            'current_password.required' => 'Введите текущий пароль.',
            'code.required' => 'Придумайте код подтверждения.',
            'code.min' => 'Код короче :min знаков.',
            'code.max' => 'Код длиннее :max знаков.',
        ]);

        $this->assertCurrentPassword($employee, $data['current_password']);

        $employee->confirm_code = $data['code'];
        $employee->save();

        return response()->json(['ok' => true]);
    }

    /** Свой токен быстрого входа: показать (его вставляют в адрес). */
    public function fastToken(Request $request): JsonResponse
    {
        /** @var Employee $employee */
        $employee = $request->user();

        return response()->json([
            'ok' => true,
            'token' => $employee->fast_token,
            'networks' => (array) config('ais_auth.token_networks'),
        ]);
    }

    /** Задать или перевыпустить свой токен быстрого входа. */
    public function changeFastToken(Request $request): JsonResponse
    {
        /** @var Employee $employee */
        $employee = $request->user();

        $data = $request->validate([
            'current_password' => ['required', 'string'],
            // Пусто = сервер придумает сам.
            'token' => ['nullable', 'string'],
        ], ['current_password.required' => 'Введите текущий пароль.']);

        $this->assertCurrentPassword($employee, $data['current_password']);

        $token = $this->presenter->setFastToken($employee, $data['token'] ?? null);

        return response()->json(['ok' => true, 'token' => $token]);
    }

    /** Отключить свой токен быстрого входа. */
    public function deleteFastToken(Request $request): JsonResponse
    {
        /** @var Employee $employee */
        $employee = $request->user();

        $employee->forceFill(['fast_token' => null, 'fast_token_hash' => null])->save();

        return response()->json(['ok' => true]);
    }

    /** Ответ со свежей сессией: cookie ставит сервер (httpOnly). */
    private function session(array $result, Request $request): JsonResponse
    {
        return response()
            ->json([
                'ok' => true,
                // Токен отдаём и в теле — им ходят не-браузерные клиенты
                // (например будущее мобильное приложение диспетчера).
                'token' => $result['token'],
                'employee' => $this->presenter->self($result['employee']),
            ])
            ->withCookie($this->auth->cookie($result['token'], $request));
    }

    private function assertCurrentPassword(Employee $employee, string $password): void
    {
        if (! Hash::check(trim($password), $employee->password)) {
            throw ValidationException::withMessages([
                'current_password' => 'Текущий пароль введён неверно.',
            ]);
        }
    }
}
