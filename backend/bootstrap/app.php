<?php

use App\Http\Middleware\AisEmployeeCan;
use App\Http\Middleware\DenyImpersonated;
use App\Http\Middleware\SiteFeatureEnabled;
use App\Services\SalesApiException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
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
        /*
         * Реальный адрес посетителя. Запрос приходит так: nginx хоста →
         * опубликованный порт → nginx в контейнере → php-fpm, поэтому
         * REMOTE_ADDR — это адрес docker-сети (172.18.0.x), и в обращениях
         * с сайта вместо IP посетителя оседало `172.18.0.1`.
         *
         * Доверяем ТОЛЬКО тем адресам, которые действительно проксируют:
         * loopback и приватные диапазоны docker. `at: '*'` так писать
         * нельзя — Symfony берёт тогда ЛЕВЫЙ элемент X-Forwarded-For, а его
         * клиент задаёт сам (nginx дописывает настоящий адрес справа), и
         * любой желающий объявил бы себя кем угодно. От этого зависят и
         * ограничение частоты обращений, и журнал заказов.
         */
        $middleware->trustProxies(at: [
            '127.0.0.1',
            '::1',
            '10.0.0.0/8',
            '172.16.0.0/12',
        ]);

        // Админские маршруты сайта вызывает АИС — сотрудника и его права
        // backend читает из БД `ais` (см. App\Services\AisAuth).
        $middleware->alias([
            'ais.can' => AisEmployeeCan::class,
            // Выключатели сайта из АИС: продажа и возврат билетов.
            'site.enabled' => SiteFeatureEnabled::class,
            // Сессия «вход в кабинет от имени покупателя» — только чтение:
            // деньги и данные покупателя из неё не трогаем.
            'not.impersonated' => DenyImpersonated::class,
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

        /*
         * Слишком частые запросы (middleware throttle). Без этого покупатель
         * видел английское «Too Many Attempts» со стектрейсом — например
         * когда несколько раз ошибся кодом из письма. Про собственные
         * лимиты кодов (попытки, пауза, суточный потолок) сообщает
         * AuthCodeService — тут именно защита от флуда по IP.
         */
        $exceptions->render(function (ThrottleRequestsException $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            $retryAfter = (int) ($e->getHeaders()['Retry-After'] ?? 60);

            return response()->json([
                'success' => false,
                'error' => 'Слишком много запросов подряд. Подождите '
                    .max(1, (int) ceil($retryAfter / 60)).' мин и попробуйте снова.',
                'retry_after' => $retryAfter,
            ], 429, $e->getHeaders());
        });

        /*
         * Код ответа зависит от ВИДА отказа (см.
         * SalesApiException::responseStatus): 404 — такого рейса,
         * остановки или станции не существует; 503 — временно недоступно
         * (станция молчит, сервис продаж перезапускается, продажа
         * выключена в АИС); 422 — запрос некорректен. Прежде всё это
         * отдавалось одним 422, и ни сайт, ни поисковик не могли
         * отличить «такого нет» от «зайди позже».
         */
        $exceptions->render(function (SalesApiException $e, Request $request) {
            $status = $e->responseStatus();

            $response = response()->json(array_filter([
                'success' => false,
                'error' => $e->getMessage(),
                'error_code' => $e->errorCode,
                'details' => $e->details ?: null,
            ], fn ($v) => $v !== null), $status);

            // 503 без Retry-After клиенты и поисковики трактуют как
            // «неизвестно когда» — у станции это минуты, не часы.
            return $status === 503 ? $response->header('Retry-After', '60') : $response;
        });

        /*
         * Штатные отказы API продаж в журнал не пишем: «данные за этот
         * период более недоступны» (поисковые роботы ходят по старым
         * ссылкам расписания), «рейс не найден», выключенная в АИС продажа.
         * На проде это 4601 запись ERROR со стектрейсом за одни сутки —
         * из-за них журнал backend разросся до 68 МБ, и настоящие сбои
         * (отказ станции в подключении) в этой массе терялись.
         *
         * Технические ошибки API продаж (sales_api_error, bad_response,
         * конфликты продажи) логируются как раньше — см.
         * SalesApiException::isExpected().
         */
        $exceptions->dontReportWhen(
            fn (\Throwable $e) => $e instanceof SalesApiException && $e->isExpected(),
        );
    })->create();
