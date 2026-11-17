<?php

use App\Exceptions\BusterException;
use App\Exceptions\StationUnavailableException;
use App\Http\Middleware\AuthenticateAgent;
use App\Http\Middleware\JournalApiRequest;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'agent.auth' => AuthenticateAgent::class,
            'journal' => JournalApiRequest::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );

        $exceptions->render(function (BusterException $e, Request $request) {
            return response()->json(array_filter([
                'success' => false,
                'error' => $e->getMessage(),
                'error_code' => $e->errorCode,
                /*
                 * Подробности отказа (результат по каждому бланку
                 * возврата): по ним вызывающая сторона видит, какой
                 * именно бланк станция отклонила, а не только «возврат
                 * не прошёл».
                 */
                'details' => $e->details ?: null,
            ], fn ($v) => $v !== null), 422);
        });

        /*
         * Станция недоступна (нет связи с её базой). Отдаём 503: это
         * временное состояние, а не ошибка запроса, — и сайт по коду
         * `station_unavailable` показывает понятный текст вместо
         * «Server Error».
         */
        $exceptions->render(function (StationUnavailableException $e, Request $request) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
                'error_code' => $e->errorCode(),
            ], 503);
        });

        /*
         * Станция отклонила операцию по своим правилам: «ведомость
         * закрыта», «нет разрешения на возврат после отправления», «место
         * уже продано», «резерв не найден». Это ШТАТНЫЙ ответ, а не сбой —
         * кассир видит причину в АИС и решает, что делать. В журнал пишем
         * одну строку уровнем notice, без стектрейса: прежде каждый такой
         * отказ попадал в ERROR со стектрейсом на 9 КБ
         * (ловилось на проде 16.11.2026: «Место5 - ведомость закрыта»).
         */
        $exceptions->reportable(function (BusterException $e) {
            Log::notice('Станция отклонила операцию', [
                'error_code' => $e->errorCode,
                'message' => $e->getMessage(),
            ]);

            // false — дальше не репортить: стектрейс здесь ничего не добавляет.
            return false;
        });

        /*
         * В журнал такие отказы пишет сам GatewayFactory — одной строкой на
         * станцию раз в пять минут. Полный стектрейс не нужен: он
         * одинаковый, а отказов бывает по две сотни в час (у станции
         * пропал интернет) — за сутки это были десятки мегабайт журнала.
         */
        $exceptions->dontReport([
            StationUnavailableException::class,
        ]);
    })->create();
