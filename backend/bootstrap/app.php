<?php

use App\Http\Middleware\AisEmployeeCan;
use App\Services\SalesApiException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Админские маршруты сайта вызывает АИС — сотрудника и его права
        // backend читает из БД `ais` (см. App\Services\AisAuth).
        $middleware->alias([
            'ais.can' => AisEmployeeCan::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );

        $exceptions->render(function (SalesApiException $e, Request $request) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
                'error_code' => $e->errorCode,
            ], 422);
        });
    })->create();
