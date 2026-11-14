<?php

use App\Http\Middleware\AuthenticateEmployee;
use App\Http\Middleware\EmployeeCan;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Снаружи в АИС попадают ТОЛЬКО через системный nginx (он же
        // единственный, кто слушает 443), поэтому его заголовкам доверяем.
        // Без этого $request->ip() равнялся бы адресу docker-сети, и вход
        // по токену «из рабочей сети» либо не работал бы вовсе, либо
        // разрешался кому угодно.
        $middleware->trustProxies(at: '*');

        $middleware->alias([
            'employee' => AuthenticateEmployee::class,
            'employee.can' => EmployeeCan::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );

        // Ответы АИС выглядят как {ok, …}, и SPA читает сообщение из
        // `error`. Стандартный ответ Laravel на 422 (`message`+`errors`)
        // она показала бы как «Ошибка запроса (HTTP 422)» — текст
        // проверки до пользователя не доходил бы.
        $exceptions->render(function (ValidationException $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return response()->json([
                'ok' => false,
                'error' => $e->validator->errors()->first(),
                'errors' => $e->errors(),
            ], 422);
        });
    })->create();
