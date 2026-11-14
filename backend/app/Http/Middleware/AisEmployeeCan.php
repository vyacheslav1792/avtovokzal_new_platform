<?php

namespace App\Http\Middleware;

use App\Services\AisAuth;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Symfony\Component\HttpFoundation\Response;

/**
 * Админские маршруты сайта (`/api/v1/admin/*`) — только для сотрудника АИС
 * с нужным правом: `ais.can:pages.edit`.
 *
 * До этого этапа они были открыты вообще (в коде стояла пометка «авторизация
 * на этапе users, на прод без неё не открывать»): в legacy доступ закрывало
 * ограничение по IP на nginx, и внутри сети возврат билета мог оформить кто
 * угодно с любой машины.
 *
 * Код подтверждения опасного действия спрашивается по тому же каталогу прав
 * (`confirm` модуля в shared/ais_permissions.php), что и в самой АИС, —
 * клиент повторяет запрос с заголовком `X-Confirm-Code`.
 */
class AisEmployeeCan
{
    public function __construct(private readonly AisAuth $auth) {}

    public function handle(Request $request, Closure $next, string $permission): Response
    {
        $employee = $this->auth->employee($request);

        if (! $employee) {
            return response()->json([
                'success' => false,
                'error' => 'Нужно войти в АИС.',
                'unauthenticated' => true,
            ], 401);
        }

        if (! $employee->can($permission)) {
            return response()->json([
                'success' => false,
                'error' => 'Недостаточно прав для этого действия.',
                'forbidden' => true,
            ], 403);
        }

        if ($this->needsConfirm($permission)) {
            if (! $employee->hasConfirmCode()) {
                return response()->json([
                    'success' => false,
                    'error' => 'У вас не задан код подтверждения опасных действий. Задайте его в профиле АИС.',
                ], 403);
            }

            $code = $request->header('X-Confirm-Code');

            if ($code === null || ! Hash::check(trim($code), (string) $employee->confirmCodeHash)) {
                return response()->json([
                    'success' => false,
                    'error' => $code === null
                        ? 'Действие требует подтверждения кодом.'
                        : 'Код подтверждения неверен.',
                    'need_confirm' => true,
                ], $code === null ? 428 : 403);
            }
        }

        // Сотрудник нужен контроллерам возврата: в журнал возврата пишется,
        // кто его оформил.
        $request->setUserResolver(fn () => $employee);

        return $next($request);
    }

    /** Требует ли право кода подтверждения (каталог shared/ais_permissions.php). */
    private function needsConfirm(string $permission): bool
    {
        [$module, $action] = array_pad(explode('.', $permission, 2), 2, '');

        return in_array($action, config("ais_modules.modules.$module.confirm", []), true);
    }
}
