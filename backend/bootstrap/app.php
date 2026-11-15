<?php

use App\Http\Middleware\AisEmployeeCan;
use App\Http\Middleware\SiteFeatureEnabled;
use App\Services\SalesApiException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

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
            // Выключатели сайта из АИС: продажа и возврат билетов.
            'site.enabled' => SiteFeatureEnabled::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );

        /*
         * Режим обслуживания (`php artisan down`) — его включают на время
         * деплоя, миграций и переноса данных. Сайту нужен распознаваемый
         * ответ, а не «Service Unavailable» со стектрейсом: по признаку
         * maintenance он показывает страницу обслуживания, а не ошибку
         * «сервис сломался».
         *
         * Ловим именно 503: своего класса исключения у middleware
         * обслуживания нет — оно бросает обычный HttpException
         * (PreventRequestsDuringMaintenance), поэтому дополнительно
         * спрашиваем приложение, действительно ли оно «down».
         */
        $exceptions->render(function (HttpException $e, Request $request) {
            if ($e->getStatusCode() !== 503 || ! $request->is('api/*') || ! app()->isDownForMaintenance()) {
                return null;
            }

            $retryAfter = $e->getHeaders()['Retry-After'] ?? null;

            return response()->json([
                'success' => false,
                'maintenance' => true,
                'error' => config('site.maintenance_text'),
                'retry_after' => $retryAfter === null ? null : (int) $retryAfter,
            ], 503, $e->getHeaders());
        });

        $exceptions->render(function (SalesApiException $e, Request $request) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
                'error_code' => $e->errorCode,
            ], 422);
        });
    })->create();
