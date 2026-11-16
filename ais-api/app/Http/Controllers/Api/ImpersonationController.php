<?php

namespace App\Http\Controllers\Api;

use App\Models\Employee;
use App\Services\EmployeeAuth;
use App\Services\EmployeeImpersonator;
use App\Services\EmployeePresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Cookie;

/**
 * Работа в АИС от имени другого сотрудника (право
 * `employees.impersonate`, помечено в каталоге прав как требующее кода
 * подтверждения) и возврат к себе.
 *
 * В legacy этого не было, как и самой авторизации: доступ закрывало
 * ограничение по IP, и «увидеть глазами сотрудника» было нечего — все
 * видели одно и то же.
 */
class ImpersonationController extends Controller
{
    public function __construct(
        private readonly EmployeeImpersonator $impersonator,
        private readonly EmployeeAuth $auth,
        private readonly EmployeePresenter $presenter,
    ) {}

    /** Начать работу от имени сотрудника. */
    public function start(Request $request, int $id): JsonResponse
    {
        /** @var Employee $admin */
        $admin = $request->user();
        $target = Employee::findOrFail($id);

        $result = $this->impersonator->start($target, $admin, $request);

        return response()
            ->json([
                'ok' => true,
                'employee' => $this->presenter->self($result['employee']),
                'impersonated' => true,
                'impersonated_by' => $admin->login,
                'expires_at' => $result['expires_at']->toIso8601String(),
                'minutes' => (int) config('ais_auth.impersonate_minutes'),
            ])
            // Основная cookie — сессия сотрудника, вторая — своя, чтобы
            // вернуться к себе. Обе httpOnly: это полноценные токены входа.
            ->withCookie($this->auth->cookie($result['token'], $request))
            ->withCookie($this->impersonatorCookie($result['admin_token'], $request));
    }

    /** Вернуться к себе. */
    public function stop(Request $request): JsonResponse
    {
        $result = $this->impersonator->stop($request);

        return response()
            ->json([
                'ok' => true,
                'employee' => $this->presenter->self($result['employee']),
                'impersonated' => false,
            ])
            ->withCookie($this->auth->cookie($result['token'], $request))
            ->withCookie(Cookie::forget(
                (string) config('ais_auth.impersonator_cookie', 'ais_session_admin'),
                '/',
            ));
    }

    /**
     * Cookie с сессией самого администратора. Живёт столько же, сколько
     * сама работа «от имени», плюс небольшой запас: истеки она раньше
     * времени — вернуться к себе было бы нельзя.
     */
    private function impersonatorCookie(string $token, Request $request)
    {
        return Cookie::make(
            name: (string) config('ais_auth.impersonator_cookie', 'ais_session_admin'),
            value: $token,
            minutes: max(1, (int) config('ais_auth.impersonate_minutes')) + 60,
            path: '/',
            domain: null,
            secure: $request->isSecure(),
            httpOnly: true,
            raw: false,
            sameSite: 'lax',
        );
    }
}
