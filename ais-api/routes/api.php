<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\EmployeeController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\RoleController;
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

    Route::middleware('employee')->group(function () {
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
