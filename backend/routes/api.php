<?php

use App\Http\Controllers\Api\AdminContactController;
use App\Http\Controllers\Api\AdminFeedbackController;
use App\Http\Controllers\Api\AdminMenuController;
use App\Http\Controllers\Api\AdminPageController;
use App\Http\Controllers\Api\AdminRefundController;
use App\Http\Controllers\Api\AdminSpamRuleController;
use App\Http\Controllers\Api\AdminUploadController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CabinetController;
use App\Http\Controllers\Api\CabinetPassengerController;
use App\Http\Controllers\Api\CatalogController;
use App\Http\Controllers\Api\ContactController;
use App\Http\Controllers\Api\FeedbackController;
use App\Http\Controllers\Api\FileController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\PageController;
use App\Http\Controllers\Api\RefundController;
use App\Http\Controllers\Api\ScheduleController;
use App\Http\Controllers\Api\VkAuthController;
use App\Models\Order;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::get('stations', [CatalogController::class, 'stations']);
    Route::get('stations/{awtcod}/destinations', [CatalogController::class, 'destinations'])->whereNumber('awtcod');
    Route::get('trips', [CatalogController::class, 'trips']);
    Route::get('trips/places', [CatalogController::class, 'places']);
    Route::get('citizenships', [CatalogController::class, 'citizenships']);

    // Регулярное расписание (страница /raspisanie-avtobusov).
    Route::get('schedule', [ScheduleController::class, 'index']);
    Route::get('tariff', [ScheduleController::class, 'tariff']);
    Route::get('schedule/{station}', [ScheduleController::class, 'show']);

    // Контакты (страница /contacts).
    Route::get('contacts', [ContactController::class, 'index']);

    // Обратная связь (перенос модалки «Написать сообщение» legacy).
    // Ограничение частоты — по IP, лимит в config/feedback.php.
    Route::get('feedback/form', [FeedbackController::class, 'form']);
    Route::post('feedback', [FeedbackController::class, 'store'])->middleware('throttle:feedback');

    // Обычные страницы сайта (legacy avtovokzal57/url/<slug>.php) и меню.
    Route::get('menu', [PageController::class, 'menu']);
    Route::get('pages/{slug}', [PageController::class, 'show']);

    // Файлы страниц по прежним адресам legacy: сайт проксирует сюда /docs/*.
    Route::get('files/{path}', [FileController::class, 'show'])->where('path', '.*');

    Route::post('orders', [OrderController::class, 'store']);
    Route::post('orders/confirm', [OrderController::class, 'confirm']);
    // id заказа — uuid у новых покупок и 30 hex-символов у импортированных
    // из legacy (см. миграцию legacy_order_id_and_raw_fields), поэтому шаблон
    // маршрута шире whereUuid.
    Route::get('orders/{id}', [OrderController::class, 'show'])->where('id', Order::ID_PATTERN);
    Route::get('orders/{id}/print', [OrderController::class, 'printData'])->where('id', Order::ID_PATTERN);
    Route::get('orders/{id}/pdf', [OrderController::class, 'pdf'])->where('id', Order::ID_PATTERN);

    Route::post('refunds/preview', [RefundController::class, 'preview']);
    Route::post('refunds/confirm', [RefundController::class, 'confirm']);

    // Личный кабинет: вход/регистрация/восстановление пароля.
    // throttle — защита от перебора паролей и рассылки писем.
    Route::middleware('throttle:10,1')->group(function () {
        Route::post('auth/register', [AuthController::class, 'register']);
        Route::post('auth/login', [AuthController::class, 'login']);
        Route::post('auth/password/forgot', [AuthController::class, 'forgotPassword']);
        Route::post('auth/password/reset', [AuthController::class, 'resetPassword']);
        Route::post('auth/invite', [AuthController::class, 'invite']);
        Route::post('auth/email/confirm', [AuthController::class, 'confirmEmail']);

        // Вход через VK ID: сюда ходит сервер Nuxt, а не браузер.
        Route::post('auth/vk/start', [VkAuthController::class, 'start']);
        Route::post('auth/vk/callback', [VkAuthController::class, 'callback']);
    });

    // Какие способы входа настроены (кнопка «Войти через VK» на страницах).
    Route::get('auth/providers', [VkAuthController::class, 'providers']);

    Route::middleware('auth:sanctum')->group(function () {
        Route::get('auth/me', [AuthController::class, 'me']);
        Route::post('auth/logout', [AuthController::class, 'logout']);
        Route::put('auth/profile', [AuthController::class, 'updateProfile']);
        Route::post('auth/password/change', [AuthController::class, 'changePassword']);
        Route::post('auth/email', [AuthController::class, 'requestEmail']);

        // Сохранённые пассажиры: подставляются в форму покупки.
        Route::get('cabinet/passengers', [CabinetPassengerController::class, 'index']);
        Route::post('cabinet/passengers', [CabinetPassengerController::class, 'store']);
        Route::put('cabinet/passengers/{id}', [CabinetPassengerController::class, 'update'])->whereNumber('id');
        Route::delete('cabinet/passengers/{id}', [CabinetPassengerController::class, 'destroy'])->whereNumber('id');

        Route::get('cabinet/orders', [CabinetController::class, 'orders']);
        Route::get('cabinet/orders/{id}', [CabinetController::class, 'order'])->where('id', Order::ID_PATTERN);
        Route::post('cabinet/refunds/preview', [CabinetController::class, 'refundPreview']);
        Route::post('cabinet/refunds/confirm', [CabinetController::class, 'refundConfirm']);
    });

    // Админский возврат (перенос ais/back_bilet.php); авторизация — на этапе
    // users, на прод без неё не открывать.
    Route::post('admin/refunds/inspect', [AdminRefundController::class, 'inspect']);
    Route::post('admin/refunds/confirm', [AdminRefundController::class, 'confirm']);
    Route::post('admin/refunds/repair', [AdminRefundController::class, 'repair']);

    // Редактирование контактов сайта из админки АИС (там же, без авторизации).
    Route::get('admin/contacts', [AdminContactController::class, 'index']);
    Route::put('admin/contacts', [AdminContactController::class, 'update']);

    // Страницы, меню и файлы сайта — там же, из админки АИС.
    Route::get('admin/pages', [AdminPageController::class, 'index']);
    Route::post('admin/pages', [AdminPageController::class, 'store']);
    Route::get('admin/pages/{page}', [AdminPageController::class, 'show']);
    Route::put('admin/pages/{page}', [AdminPageController::class, 'update']);
    Route::delete('admin/pages/{page}', [AdminPageController::class, 'destroy']);

    Route::get('admin/menu', [AdminMenuController::class, 'index']);
    Route::put('admin/menu', [AdminMenuController::class, 'update']);

    // Обращения с сайта и антиспам-словарь — там же, из админки АИС.
    Route::get('admin/feedback', [AdminFeedbackController::class, 'index']);
    Route::get('admin/feedback/{feedback}', [AdminFeedbackController::class, 'show'])->whereNumber('feedback');
    Route::put('admin/feedback/{feedback}', [AdminFeedbackController::class, 'update'])->whereNumber('feedback');
    Route::delete('admin/feedback/{feedback}', [AdminFeedbackController::class, 'destroy'])->whereNumber('feedback');

    Route::get('admin/spam-rules', [AdminSpamRuleController::class, 'index']);
    Route::put('admin/spam-rules', [AdminSpamRuleController::class, 'update']);

    Route::get('admin/uploads', [AdminUploadController::class, 'index']);
    Route::post('admin/uploads', [AdminUploadController::class, 'store']);
    Route::delete('admin/uploads', [AdminUploadController::class, 'destroy']);
});
