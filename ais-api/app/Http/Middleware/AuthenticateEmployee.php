<?php

namespace App\Http\Middleware;

use App\Services\EmployeeAuth;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Пускает дальше только сотрудника АИС. Токен — httpOnly-cookie (SPA),
 * заголовок Authorization: Bearer (внешние клиенты) или `?token=` из
 * доверенной сети (быстрый вход по ссылке).
 */
class AuthenticateEmployee
{
    public function __construct(private readonly EmployeeAuth $auth) {}

    public function handle(Request $request, Closure $next): Response
    {
        $employee = $this->auth->resolve($request);

        if (! $employee) {
            return response()->json([
                'ok' => false,
                'error' => 'Нужно войти в систему.',
                // Признак для SPA: показать форму входа, а не сообщение
                // об ошибке (её легко принять за поломку отчёта).
                'unauthenticated' => true,
            ], 401);
        }

        $request->setUserResolver(fn () => $employee);

        return $next($request);
    }
}
