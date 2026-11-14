<?php

namespace App\Http\Middleware;

use App\Models\Employee;
use App\Services\ModuleRegistry;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Право сотрудника на модуль: `employee.can:pages.edit`.
 *
 * Здесь же требуется код подтверждения опасного действия — какие права его
 * требуют, перечислено в каталоге (`confirm` модуля в
 * shared/ais_permissions.php), а не в маршрутах: иначе список опасных
 * операций разъехался бы с тем, что видит администратор в редакторе ролей.
 * Клиент повторяет запрос с заголовком `X-Confirm-Code`.
 */
class EmployeeCan
{
    public function __construct(private readonly ModuleRegistry $modules) {}

    public function handle(Request $request, Closure $next, string $permission): Response
    {
        /** @var Employee|null $employee */
        $employee = $request->user();

        if (! $employee || ! $employee->can($permission)) {
            return response()->json([
                'ok' => false,
                'error' => 'Недостаточно прав для этого действия.',
                'forbidden' => true,
            ], 403);
        }

        if ($this->modules->needsConfirm($permission)) {
            $code = $request->header('X-Confirm-Code');

            if ($employee->confirm_code === null) {
                return response()->json([
                    'ok' => false,
                    'error' => 'У вас не задан код подтверждения опасных действий. Задайте его в профиле.',
                ], 403);
            }

            if (! $employee->confirmCodeMatches($code)) {
                return response()->json([
                    'ok' => false,
                    'error' => $code === null
                        ? 'Действие требует подтверждения кодом.'
                        : 'Код подтверждения неверен.',
                    // Признак для клиента: спросить код и повторить запрос.
                    'need_confirm' => true,
                ], $code === null ? 428 : 403);
            }
        }

        return $next($request);
    }
}
