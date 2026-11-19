<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\EmployeeController;
use App\Http\Controllers\Api\ImpersonationController;
use App\Http\Controllers\Api\KkmServerController;
use App\Http\Controllers\Api\LgotController;
use App\Http\Controllers\Api\LogController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\RoleController;
use App\Http\Controllers\Api\StationController;
use App\Http\Controllers\Api\StationSalesSyncController;
use Illuminate\Support\Facades\Route;

// Контракт отчётов совместим с legacy apiv2 (SPA-рендер отчётов), но, в
// отличие от legacy, доступ закрыт авторизацией сотрудника, а не только
// ограничением по IP на nginx.
Route::prefix('v1')->group(function () {

    // Вход. throttle — защита от перебора пароля и токена.
    Route::middleware('throttle:10,1')->group(function () {
        Route::post('auth/login', [AuthController::class, 'login']);
        // Обмен токена быстрого входа из адреса на обычную сессию.
        Route::post('auth/token-login', [AuthController::class, 'tokenLogin']);
    });

    Route::middleware(['employee', 'employee.not-impersonated'])->group(function () {
        Route::get('auth/me', [AuthController::class, 'me']);
        Route::post('auth/logout', [AuthController::class, 'logout']);

        // Свои настройки: пароль, код подтверждения, токен быстрого входа.
        Route::put('auth/profile', [AuthController::class, 'updateProfile']);
        Route::post('auth/password', [AuthController::class, 'changePassword']);
        Route::put('auth/confirm-code', [AuthController::class, 'changeConfirmCode']);
        Route::get('auth/fast-token', [AuthController::class, 'fastToken']);
        Route::put('auth/fast-token', [AuthController::class, 'changeFastToken']);
        Route::delete('auth/fast-token', [AuthController::class, 'deleteFastToken']);

        // Отчёты: список фильтруется по правам, запуск их же проверяет.
        Route::get('reports', [ReportController::class, 'index'])->name('reports.index');
        Route::get('reports/{slug}/meta', [ReportController::class, 'meta'])->name('reports.meta');
        Route::post('reports/{slug}/run', [ReportController::class, 'run'])->name('reports.run');

        /*
         * Отчёт «Нулевые билеты»: у него своя страница в АИС и то, чего
         * нет у остальных отчётов, — список АТП за период и два списка
         * исключений (у каждого вида рейса свои). Правка списков вынесена
         * из запуска отчёта отдельными маршрутами: запуск обязан
         * оставаться read-only (его пропускает и наблюдательный режим
         * «работаю от имени сотрудника»).
         */
        Route::get('reports/lgot/atps', [LgotController::class, 'atps'])
            ->middleware('employee.can:report:lgot.view');
        Route::get('reports/lgot/exclusions', [LgotController::class, 'exclusions'])
            ->middleware('employee.can:report:lgot.view');
        Route::put('reports/lgot/exclusions/atps', [LgotController::class, 'saveAtps'])
            ->middleware('employee.can:report:lgot.exclude');
        Route::post('reports/lgot/exclusions/tickets', [LgotController::class, 'addTickets'])
            ->middleware('employee.can:report:lgot.exclude');
        Route::delete('reports/lgot/exclusions/tickets', [LgotController::class, 'removeTickets'])
            ->middleware('employee.can:report:lgot.exclude');

        Route::get('station-sales-sync', [StationSalesSyncController::class, 'status'])
            ->middleware('employee.can:report:station-sales.view');
        Route::post('station-sales-sync', [StationSalesSyncController::class, 'start'])
            ->middleware(['employee.can:report:station-sales.view', 'employee.can:report:station-sales.refresh']);

        /*
         * Справочник станций: что раньше правили в файле (а в legacy —
         * прямо в коде, в трёх местах). Переключение варианта подключения
         * и правка требуют кода подтверждения: ошибка здесь останавливает
         * продажу по станции.
         */
        Route::get('stations', [StationController::class, 'index'])
            ->middleware('employee.can:stations.view');
        Route::post('stations', [StationController::class, 'store'])
            ->middleware('employee.can:stations.edit');
        Route::put('stations/{awtcod}', [StationController::class, 'update'])
            ->whereNumber('awtcod')->middleware('employee.can:stations.edit');
        Route::delete('stations/{awtcod}', [StationController::class, 'destroy'])
            ->whereNumber('awtcod')->middleware('employee.can:stations.edit');
        Route::post('stations/{awtcod}/profile', [StationController::class, 'switchProfile'])
            ->whereNumber('awtcod')->middleware('employee.can:stations.edit');
        Route::post('stations/import-file', [StationController::class, 'importFromFile'])
            ->middleware('employee.can:stations.edit');
        // Проверка связи ничего не меняет — отдельное право попроще.
        Route::post('stations/{awtcod}/test', [StationController::class, 'test'])
            ->whereNumber('awtcod')->middleware('employee.can:stations.test');

        // Настройки KkmServer (фискальные чеки).
        Route::get('kkm-servers', [KkmServerController::class, 'index'])
            ->middleware('employee.can:kkm.view');
        Route::put('kkm-servers', [KkmServerController::class, 'save'])
            ->middleware('employee.can:kkm.edit');
        Route::post('kkm-servers/activate', [KkmServerController::class, 'activate'])
            ->middleware('employee.can:kkm.edit');
        Route::post('kkm-servers/test', [KkmServerController::class, 'test'])
            ->middleware('employee.can:kkm.test');

        // Сотрудники и роли. Опасные операции (сброс секретов, удаление)
        // требуют кода подтверждения — это задано в каталоге прав
        // (shared/ais_permissions.php), а не здесь.
        Route::get('employees', [EmployeeController::class, 'index'])
            ->middleware('employee.can:employees.view');
        Route::get('employees/{id}', [EmployeeController::class, 'show'])
            ->whereNumber('id')->middleware('employee.can:employees.view');
        Route::post('employees', [EmployeeController::class, 'store'])
            ->middleware('employee.can:employees.edit');
        Route::put('employees/{id}', [EmployeeController::class, 'update'])
            ->whereNumber('id')->middleware('employee.can:employees.edit');
        Route::delete('employees/{id}', [EmployeeController::class, 'destroy'])
            ->whereNumber('id')->middleware('employee.can:employees.delete');

        Route::post('employees/{id}/password', [EmployeeController::class, 'resetPassword'])
            ->whereNumber('id')->middleware('employee.can:employees.reset');
        Route::post('employees/{id}/confirm-code', [EmployeeController::class, 'resetConfirmCode'])
            ->whereNumber('id')->middleware('employee.can:employees.reset');
        Route::post('employees/{id}/fast-token', [EmployeeController::class, 'resetFastToken'])
            ->whereNumber('id')->middleware('employee.can:employees.reset');
        Route::delete('employees/{id}/fast-token', [EmployeeController::class, 'deleteFastToken'])
            ->whereNumber('id')->middleware('employee.can:employees.reset');
        Route::post('employees/{id}/logout', [EmployeeController::class, 'logoutSessions'])
            ->whereNumber('id')->middleware('employee.can:employees.reset');

        /*
         * Работа от имени сотрудника: администратор получает его короткую
         * сессию, чтобы увидеть АИС его глазами (роль на словах не
         * проверишь). Право помечено в каталоге как опасное — сервер
         * спросит код подтверждения. Возврат к себе (`stop`) права не
         * требует: он доступен только тому, у кого в cookie лежит своя
         * прежняя сессия.
         */
        Route::post('employees/{id}/impersonate', [ImpersonationController::class, 'start'])
            ->whereNumber('id')->middleware('employee.can:employees.impersonate');
        Route::post('impersonation/stop', [ImpersonationController::class, 'stop']);

        /*
         * Журналы приложений: свой читаем из storage/logs, остальные
         * приходят bind mount'ами (см. docker-compose.yml, ais_app).
         * Файлы читаются с конца — на проде laravel.log у backend под 70 МБ.
         */
        Route::get('logs', [LogController::class, 'index'])
            ->middleware('employee.can:logs.view');
        Route::get('logs/{source}', [LogController::class, 'entries'])
            ->middleware('employee.can:logs.view');
        Route::get('logs/{source}/download', [LogController::class, 'download'])
            ->middleware('employee.can:logs.download');

        Route::get('roles', [RoleController::class, 'index'])
            ->middleware('employee.can:roles.view');
        Route::post('roles', [RoleController::class, 'store'])
            ->middleware('employee.can:roles.edit');
        Route::put('roles/{id}', [RoleController::class, 'update'])
            ->whereNumber('id')->middleware('employee.can:roles.edit');
        Route::delete('roles/{id}', [RoleController::class, 'destroy'])
            ->whereNumber('id')->middleware('employee.can:roles.delete');
    });
});
