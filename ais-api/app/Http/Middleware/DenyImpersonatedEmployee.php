<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Сессия «работа от имени сотрудника» — только наблюдение.
 *
 * Администратор входит в чужую учётную запись, чтобы УВИДЕТЬ АИС его
 * глазами: какие разделы в меню, какие отчёты открываются, появляется ли
 * кнопка возврата. Что-либо менять из неё нельзя — правка страницы сайта,
 * сброс пароля или возврат денег попали бы в журнал на имя сотрудника,
 * который их не делал.
 *
 * Разрешены: все GET, запуск отчётов (он POST, но ничего не меняет —
 * это и есть главное, зачем входят), выход и возврат к себе.
 */
class DenyImpersonatedEmployee
{
    /** Изменяющие запросы, которые режим всё же пропускает. */
    private const ALLOWED = [
        'api/v1/reports/*/run',
        'api/v1/auth/logout',
        'api/v1/impersonation/stop',
        // Проверки связи ничего не меняют, но и смотреть их «глазами
        // сотрудника» незачем — они в список не входят.
    ];

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->isMethodSafe() || $request->is(self::ALLOWED)) {
            return $next($request);
        }

        if ($request->user()?->isImpersonated()) {
            return response()->json([
                'ok' => false,
                'error' => 'Вы работаете от имени сотрудника — в этом режиме можно только смотреть. '
                    .'Вернитесь к себе, чтобы что-то изменить.',
                'impersonation_readonly' => true,
            ], 403);
        }

        return $next($request);
    }
}
