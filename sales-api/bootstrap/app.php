<?php

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
        $middleware->alias([
            'agent.auth' => \App\Http\Middleware\AuthenticateAgent::class,
            'journal' => \App\Http\Middleware\JournalApiRequest::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );

        $exceptions->render(function (\App\Exceptions\BusterException $e, Request $request) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
                'error_code' => $e->errorCode,
            ], 422);
        });

        /*
         * Станция недоступна (нет связи с её базой). Отдаём 503: это
         * временное состояние, а не ошибка запроса, — и сайт по коду
         * `station_unavailable` показывает понятный текст вместо
         * «Server Error».
         */
        $exceptions->render(function (\App\Exceptions\StationUnavailableException $e, Request $request) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
                'error_code' => $e->errorCode(),
            ], 503);
        });

        /*
         * В журнал такие отказы пишет сам GatewayFactory — одной строкой на
         * станцию раз в пять минут. Полный стектрейс не нужен: он
         * одинаковый, а отказов бывает по две сотни в час (у станции
         * пропал интернет) — за сутки это были десятки мегабайт журнала.
         */
        $exceptions->dontReport([
            \App\Exceptions\StationUnavailableException::class,
        ]);
    })->create();
