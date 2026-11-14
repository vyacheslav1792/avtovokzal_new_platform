<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\EmployeeToken;
use App\Support\IpNetworks;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Cookie as SymfonyCookie;

/**
 * Вход сотрудника в АИС и разбор токена входящего запроса.
 *
 * Два способа входа:
 *   — логин и пароль (обычный, работает откуда угодно);
 *   — токен быстрого входа в адресе (`?token=…`) — ТОЛЬКО из сетей
 *     `ais_auth.token_networks`. Токен виден в журналах прокси и в истории
 *     браузера, поэтому вне доверенной сети он не действует вообще; именно
 *     он заменяет пароль там, где входить каждый раз неудобно.
 *
 * Оба способа заканчиваются одним и тем же: сессией в `employee_tokens`,
 * которую SPA дальше присылает httpOnly-cookie (а не-браузерный клиент —
 * заголовком Authorization: Bearer).
 */
class EmployeeAuth
{
    /** Вход по логину и паролю. */
    public function login(string $login, string $password, Request $request): array
    {
        // trim обязателен и при проверке: пароль задавался обрезанным,
        // а при вводе пробел легко прилетает из буфера обмена.
        $employee = Employee::findByLogin($login);
        $password = trim($password);

        if (! $employee || ! Hash::check($password, $employee->password)) {
            // Какой именно реквизит неверен — не говорим: иначе логины
            // сотрудников можно перебрать по разным ответам.
            throw ValidationException::withMessages([
                'login' => 'Неверный логин или пароль.',
            ]);
        }

        $this->assertActive($employee);

        return $this->issue($employee, 'password', $request);
    }

    /** Вход по токену быстрого входа (адрес вида /?token=…). */
    public function loginByToken(string $token, Request $request): array
    {
        $networks = (array) config('ais_auth.token_networks');

        if ($networks === []) {
            throw ValidationException::withMessages([
                'token' => 'Вход по токену отключён.',
            ]);
        }

        if (! IpNetworks::matches($request->ip(), $networks)) {
            throw ValidationException::withMessages([
                'token' => 'Вход по токену разрешён только из рабочей сети. Войдите по логину и паролю.',
            ]);
        }

        $employee = Employee::where('fast_token_hash', EmployeeToken::hash(trim($token)))->first();

        if (! $employee) {
            throw ValidationException::withMessages([
                'token' => 'Токен не найден. Возможно, он изменён — войдите по логину и паролю.',
            ]);
        }

        $this->assertActive($employee);

        return $this->issue($employee, 'token', $request);
    }

    /**
     * Сотрудник текущего запроса или null. Токен берётся из cookie
     * (обычный путь SPA), из заголовка Authorization (внешние клиенты)
     * либо из `?token=` — последний действует как вход по токену, то есть
     * только из доверенной сети и без создания сессии.
     */
    public function resolve(Request $request): ?Employee
    {
        $token = $this->sessionTokenFromRequest($request);

        if ($token !== null) {
            $session = EmployeeToken::where('token_hash', EmployeeToken::hash($token))->first();

            if ($session && $session->isAlive()) {
                $employee = $session->employee;

                if ($employee && $employee->is_active) {
                    $session->touchUsage();
                    $employee->setRelation('currentToken', $session);

                    return $employee;
                }
            }

            // Мёртвую или чужую сессию дальше не тащим — её владельца нет.
            $session?->delete();
        }

        return $this->employeeByFastToken($request);
    }

    /**
     * Сотрудник по токену быстрого входа из адреса. Сессию при этом не
     * заводим: такой запрос самодостаточен (им ходят по прямым ссылкам
     * и из сторонних программ), а сеть проверяется каждый раз.
     */
    public function employeeByFastToken(Request $request): ?Employee
    {
        $token = trim((string) $request->query('token', ''));

        if ($token === '' || ! IpNetworks::matches($request->ip(), (array) config('ais_auth.token_networks'))) {
            return null;
        }

        $employee = Employee::where('fast_token_hash', EmployeeToken::hash($token))->first();

        return $employee && $employee->is_active ? $employee : null;
    }

    /** Токен сессии из cookie или заголовка Authorization. */
    public function sessionTokenFromRequest(Request $request): ?string
    {
        $bearer = $request->bearerToken();
        if (is_string($bearer) && $bearer !== '') {
            return $bearer;
        }

        $cookie = $request->cookie((string) config('ais_auth.cookie', 'ais_session'));

        return is_string($cookie) && $cookie !== '' ? $cookie : null;
    }

    /** Погасить сессию текущего запроса. */
    public function logout(Request $request): void
    {
        $token = $this->sessionTokenFromRequest($request);

        if ($token !== null) {
            EmployeeToken::where('token_hash', EmployeeToken::hash($token))->delete();
        }
    }

    /**
     * Cookie с токеном сессии. Ставит её сервер и помечает httpOnly:
     * сессия живёт месяцами, а записанное из JS Safari/ITP стирает
     * примерно через неделю.
     */
    public function cookie(string $token, Request $request): SymfonyCookie
    {
        return Cookie::make(
            name: (string) config('ais_auth.cookie', 'ais_session'),
            value: $token,
            minutes: $this->cookieMinutes(),
            path: '/',
            domain: null,
            // На локальном контуре АИС открывают по http — там Secure
            // cookie браузер бы отбросил (кроме localhost).
            secure: $request->isSecure(),
            httpOnly: true,
            raw: false,
            // Lax закрывает CSRF: с чужого сайта POST/PUT/DELETE cookie
            // не получит, а переход по ссылке (GET) — получит.
            sameSite: 'lax',
        );
    }

    /** Cookie «удалено» — для выхода. */
    public function forgetCookie(): SymfonyCookie
    {
        return Cookie::forget((string) config('ais_auth.cookie', 'ais_session'), '/');
    }

    /** Токен быстрого входа, который предлагает сервер. */
    public static function generateFastToken(): string
    {
        return Str::random((int) config('ais_auth.fast_token.generate', 24));
    }

    private function cookieMinutes(): int
    {
        $idle = (int) config('ais_auth.session_idle_minutes');
        $max = (int) config('ais_auth.session_max_minutes');

        // Cookie не должна жить дольше самой сессии; при отключённом
        // предельном сроке её границу задаёт простой.
        return $max > 0 ? min($idle, $max) : $idle;
    }

    private function assertActive(Employee $employee): void
    {
        if (! $employee->is_active) {
            throw ValidationException::withMessages([
                'login' => 'Учётная запись отключена. Обратитесь к администратору АИС.',
            ]);
        }
    }

    /** @return array{employee: Employee, token: string} */
    private function issue(Employee $employee, string $kind, Request $request): array
    {
        [, $token] = EmployeeToken::issue(
            $employee,
            $kind,
            (string) $request->ip(),
            (string) $request->userAgent(),
        );

        $employee->forceFill([
            'last_login_at' => now(),
            'last_login_ip' => (string) $request->ip(),
        ])->saveQuietly();

        return ['employee' => $employee, 'token' => $token];
    }
}
