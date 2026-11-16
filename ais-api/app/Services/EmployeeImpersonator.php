<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\EmployeeImpersonation;
use App\Models\EmployeeToken;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Работа в АИС от имени другого сотрудника (право `employees.impersonate`).
 *
 * Зачем: роль сотрудника на словах не проверишь. «У меня нет этого
 * отчёта», «кнопка возврата не появляется» — быстрее один раз увидеть
 * АИС его глазами, чем сверять галочки в редакторе ролей.
 *
 * Как устроено: администратор получает ОТДЕЛЬНУЮ короткую сессию этого
 * сотрудника (`employee_tokens.kind = impersonate`, `impersonated_by` —
 * его собственный id, `expires_at` — час от выдачи), а его собственная
 * сессия НЕ гасится: её токен на это время лежит во второй httpOnly
 * cookie, по ней он и возвращается к себе. Так не приходится ни хранить
 * чужой пароль, ни заново входить.
 *
 * Режим наблюдательный: изменяющие запросы запрещены (middleware
 * DenyImpersonatedEmployee здесь и AisEmployeeCan в backend) — иначе
 * правка страницы или возврат денег попали бы в журнал на имя сотрудника,
 * который их не делал.
 */
class EmployeeImpersonator
{
    public function __construct(private readonly EmployeeAuth $auth) {}

    /**
     * Начать работу от имени сотрудника.
     *
     * @return array{employee: Employee, token: string, admin_token: string, expires_at: \Illuminate\Support\Carbon}
     */
    public function start(Employee $target, Employee $admin, Request $request): array
    {
        if ($target->id === $admin->id) {
            throw ValidationException::withMessages([
                'employee' => 'Это вы и есть — от своего имени вы уже работаете.',
            ]);
        }

        if (! $target->is_active) {
            throw ValidationException::withMessages([
                'employee' => 'Учётная запись отключена. Сначала включите её, иначе увидите только экран входа.',
            ]);
        }

        // Повторная «матрёшка» запрещена: иначе по журналу не понять, кто
        // в итоге работал, а вернуться к себе можно было бы только
        // перебором сохранённых cookie.
        $current = $this->currentSession($request);

        if ($current?->isImpersonated()) {
            throw ValidationException::withMessages([
                'employee' => 'Вы уже работаете от имени сотрудника. Сначала вернитесь к себе.',
            ]);
        }

        $adminToken = $this->auth->sessionTokenFromRequest($request);

        if ($adminToken === null) {
            // Вход по `?token=` сессии не создаёт — возвращаться было бы
            // некуда, поэтому требуем обычную сессию.
            throw ValidationException::withMessages([
                'employee' => 'Войдите в АИС по логину и паролю: из входа по ссылке-токену '
                    .'вернуться к себе будет нельзя.',
            ]);
        }

        $minutes = max(1, (int) config('ais_auth.impersonate_minutes'));
        $expiresAt = now()->addMinutes($minutes);

        [$session, $token] = EmployeeToken::issue(
            $target,
            EmployeeToken::KIND_IMPERSONATE,
            (string) $request->ip(),
            (string) $request->userAgent(),
        );

        $session->forceFill([
            'impersonated_by' => $admin->id,
            'expires_at' => $expiresAt,
        ])->save();

        // last_login_at сотруднику НЕ двигаем: он не входил, и «последний
        // вход» в его карточке должен оставаться правдой.
        EmployeeImpersonation::create([
            'employee_id' => $target->id,
            'employee_login' => $target->login,
            'admin_id' => $admin->id,
            'admin_login' => $admin->login,
            'ip' => (string) $request->ip(),
            'user_agent' => mb_substr((string) $request->userAgent(), 0, 250),
            'started_at' => now(),
        ]);

        return [
            'employee' => $target,
            'token' => $token,
            'admin_token' => $adminToken,
            'expires_at' => $expiresAt,
        ];
    }

    /**
     * Вернуться к себе: гасим сессию «от имени» и возвращаем сессию
     * администратора из второй cookie.
     *
     * @return array{employee: Employee, token: string}
     */
    public function stop(Request $request): array
    {
        $session = $this->currentSession($request);

        if (! $session?->isImpersonated()) {
            throw ValidationException::withMessages([
                'impersonation' => 'Вы не работаете от имени сотрудника.',
            ]);
        }

        $adminToken = (string) $request->cookie(
            (string) config('ais_auth.impersonator_cookie', 'ais_session_admin'),
            '',
        );

        $adminSession = $adminToken === ''
            ? null
            : EmployeeToken::where('token_hash', EmployeeToken::hash($adminToken))->first();

        // Сессия администратора должна быть и живой, и ТОЙ САМОЙ: иначе
        // по подложенной cookie можно было бы «вернуться» в чужую
        // учётную запись.
        if (! $adminSession || ! $adminSession->isAlive()
            || $adminSession->employee_id !== $session->impersonated_by) {
            // Возвращаться некуда — просто выходим совсем, чтобы не
            // оставлять живой чужую сессию.
            $session->delete();
            $this->closeLog($session);

            throw ValidationException::withMessages([
                'impersonation' => 'Ваша прежняя сессия больше не действует — войдите в АИС заново.',
            ]);
        }

        $admin = $adminSession->employee;

        if (! $admin || ! $admin->is_active) {
            $session->delete();
            $this->closeLog($session);

            throw ValidationException::withMessages([
                'impersonation' => 'Ваша учётная запись недоступна — войдите в АИС заново.',
            ]);
        }

        $session->delete();
        $this->closeLog($session);

        return ['employee' => $admin, 'token' => $adminToken];
    }

    /** Текущая сессия запроса (её же нашёл EmployeeAuth::resolve). */
    private function currentSession(Request $request): ?EmployeeToken
    {
        $token = $this->auth->sessionTokenFromRequest($request);

        return $token === null
            ? null
            : EmployeeToken::where('token_hash', EmployeeToken::hash($token))->first();
    }

    /** Закрыть открытую запись журнала этой пары «администратор — сотрудник». */
    private function closeLog(EmployeeToken $session): void
    {
        // Именно «найти и сохранить», а не UPDATE … LIMIT 1: Postgres
        // такого UPDATE не умеет вовсе (это есть в MySQL).
        $log = EmployeeImpersonation::where('employee_id', $session->employee_id)
            ->where('admin_id', $session->impersonated_by)
            ->whereNull('ended_at')
            ->orderByDesc('started_at')
            ->first();

        $log?->forceFill(['ended_at' => now()])->save();
    }
}
