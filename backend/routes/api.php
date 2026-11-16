<?php

use App\Http\Controllers\Api\AdminContactController;
use App\Http\Controllers\Api\AdminCustomerController;
use App\Http\Controllers\Api\AdminDataSyncController;
use App\Http\Controllers\Api\AdminFeedbackController;
use App\Http\Controllers\Api\AdminMenuController;
use App\Http\Controllers\Api\AdminNewsController;
use App\Http\Controllers\Api\AdminPageController;
use App\Http\Controllers\Api\AdminRefundController;
use App\Http\Controllers\Api\AdminSettingController;
use App\Http\Controllers\Api\AdminSiteBlockController;
use App\Http\Controllers\Api\AdminSpamRuleController;
use App\Http\Controllers\Api\AdminUploadController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CabinetController;
use App\Http\Controllers\Api\CabinetPassengerController;
use App\Http\Controllers\Api\CatalogController;
use App\Http\Controllers\Api\ContactController;
use App\Http\Controllers\Api\FeedbackController;
use App\Http\Controllers\Api\FileController;
use App\Http\Controllers\Api\NewsController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\PageController;
use App\Http\Controllers\Api\RefundController;
use App\Http\Controllers\Api\ScheduleController;
use App\Http\Controllers\Api\SettingController;
use App\Http\Controllers\Api\SiteBlockController;
use App\Http\Controllers\Api\VkAuthController;
use App\Models\Order;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::get('stations', [CatalogController::class, 'stations']);
    Route::get('stations/{awtcod}/destinations', [CatalogController::class, 'destinations'])->whereNumber('awtcod');
    Route::get('trips', [CatalogController::class, 'trips']);
    Route::get('trips/places', [CatalogController::class, 'places']);
    Route::get('citizenships', [CatalogController::class, 'citizenships']);
    // Правила продажи для формы покупки (детский тариф только с полным).
    Route::get('sale-rules', [CatalogController::class, 'saleRules']);

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

    // Новости и объявления (в legacy — vidjets/news.php вместе с вёрсткой).
    Route::get('news', [NewsController::class, 'index']);
    Route::get('news/{slug}', [NewsController::class, 'show'])
        ->where('slug', '[a-z0-9][a-z0-9\-]*');

    // Блоки сайта («виджеты» legacy vidjets/*.php): ключ слота → HTML.
    Route::get('blocks', [SiteBlockController::class, 'index']);

    // Выключатели сайта из АИС: включены ли продажа и возврат билетов.
    Route::get('settings', [SettingController::class, 'index']);

    // Файлы страниц по прежним адресам legacy: сайт проксирует сюда /docs/*.
    Route::get('files/{path}', [FileController::class, 'show'])->where('path', '.*');

    // Продажу можно выключить из АИС (раздел «Настройки»). Дооформление
    // уже оплаченного заказа (confirm) выключателю НЕ подчиняется: деньги
    // у нас, билеты обязаны появиться.
    Route::post('orders', [OrderController::class, 'store'])
        ->middleware(['site.enabled:sales', 'not.impersonated']);
    Route::post('orders/confirm', [OrderController::class, 'confirm']);
    // Незавершённые покупки (покупатель ушёл на шлюз и не вернулся).
    // Ходит с каждой страницы сайта и дёргает шлюз — поэтому throttle.
    Route::post('orders/pending', [OrderController::class, 'pending'])->middleware('throttle:30,1');
    // id заказа — uuid у новых покупок и 30 hex-символов у импортированных
    // из legacy (см. миграцию legacy_order_id_and_raw_fields), поэтому шаблон
    // маршрута шире whereUuid.
    Route::get('orders/{id}', [OrderController::class, 'show'])->where('id', Order::ID_PATTERN);
    // Отказ от неоплаченного заказа: снимает бронь мест и отменяет платёж.
    Route::post('orders/{id}/cancel', [OrderController::class, 'cancel'])
        ->where('id', Order::ID_PATTERN)->middleware(['throttle:20,1', 'not.impersonated']);
    Route::get('orders/{id}/print', [OrderController::class, 'printData'])->where('id', Order::ID_PATTERN);
    Route::get('orders/{id}/pdf', [OrderController::class, 'pdf'])->where('id', Order::ID_PATTERN);

    // Возврат по коду тоже выключается из АИС; возврат через АИС
    // (admin/refunds/*) при этом продолжает работать — это инструмент кассира.
    Route::post('refunds/preview', [RefundController::class, 'preview'])->middleware('site.enabled:refunds');
    Route::post('refunds/confirm', [RefundController::class, 'confirm'])
        ->middleware(['site.enabled:refunds', 'not.impersonated']);

    // Личный кабинет: вход/регистрация/восстановление пароля.
    // throttle — защита от перебора паролей и рассылки писем.
    Route::middleware('throttle:10,1')->group(function () {
        // Шаг 1 регистрации и запрос кода: за ними стоит отправка письма.
        Route::post('auth/register', [AuthController::class, 'register']);
        Route::post('auth/login', [AuthController::class, 'login']);
        Route::post('auth/password/forgot', [AuthController::class, 'forgotPassword']);
        // Повторная отправка кода. Пауза между письмами (3 минуты) и
        // суточный потолок на адрес — в AuthCodeService.
        Route::post('auth/code/resend', [AuthController::class, 'resendCode']);
        Route::post('auth/invite', [AuthController::class, 'invite']);

        // Вход через VK ID: сюда ходит сервер Nuxt, а не браузер.
        Route::post('auth/vk/start', [VkAuthController::class, 'start']);
        Route::post('auth/vk/callback', [VkAuthController::class, 'callback']);

        /*
         * Вход в кабинет от имени покупателя: сотрудник получает в АИС
         * одноразовый код (customers.impersonate), сервер Nuxt меняет его
         * здесь на токен кабинета и кладёт в свою httpOnly-cookie. Токен
         * помечен способностью `impersonated` — покупать, возвращать и
         * менять данные им нельзя (middleware not.impersonated).
         */
        Route::post('auth/impersonate', [AuthController::class, 'impersonate']);
    });

    /*
     * Ввод кода из письма — лимит свободнее: перебор закрыт самим кодом
     * (пять попыток на пару «адрес + код», потом он гасится), а жёсткие
     * 10 запросов в минуту отвечали «слишком много запросов» человеку,
     * который всего лишь ошибся цифрой и попросил новый код.
     */
    Route::middleware('throttle:30,1')->group(function () {
        Route::post('auth/register/confirm', [AuthController::class, 'confirmRegistration']);
        Route::post('auth/password/reset', [AuthController::class, 'resetPassword']);
    });

    // Какие способы входа настроены (кнопка «Войти через VK» на страницах).
    Route::get('auth/providers', [VkAuthController::class, 'providers']);

    Route::middleware('auth:sanctum')->group(function () {
        Route::get('auth/me', [AuthController::class, 'me']);
        Route::post('auth/logout', [AuthController::class, 'logout']);
        /*
         * Всё, что МЕНЯЕТ кабинет, закрыто для сессии «вход от имени
         * покупателя»: сотрудник в ней только смотрит. Сам выход
         * (auth/logout) разрешён — иначе из режима было бы не выйти.
         */
        Route::middleware('not.impersonated')->group(function () {
            Route::put('auth/profile', [AuthController::class, 'updateProfile']);
            Route::post('auth/password/change', [AuthController::class, 'changePassword']);
            Route::post('auth/email', [AuthController::class, 'requestEmail']);
            // Подтверждение добавленного адреса кодом — только своему кабинету.
            Route::post('auth/email/confirm', [AuthController::class, 'confirmEmail']);

            Route::post('cabinet/passengers', [CabinetPassengerController::class, 'store']);
            Route::put('cabinet/passengers/{id}', [CabinetPassengerController::class, 'update'])->whereNumber('id');
            Route::delete('cabinet/passengers/{id}', [CabinetPassengerController::class, 'destroy'])->whereNumber('id');
        });

        // Сохранённые пассажиры: подставляются в форму покупки.
        Route::get('cabinet/passengers', [CabinetPassengerController::class, 'index']);

        Route::get('cabinet/orders', [CabinetController::class, 'orders']);
        Route::get('cabinet/orders/{id}', [CabinetController::class, 'order'])->where('id', Order::ID_PATTERN);
        // Возврат из кабинета — тот же возврат по коду, поэтому слушается
        // тот же выключатель: иначе выключенный возврат остался бы доступен
        // всякому, кто завёл кабинет.
        Route::post('cabinet/refunds/preview', [CabinetController::class, 'refundPreview'])
            ->middleware('site.enabled:refunds');
        Route::post('cabinet/refunds/confirm', [CabinetController::class, 'refundConfirm'])
            ->middleware(['site.enabled:refunds', 'not.impersonated']);
    });

    /*
     * Админское API сайта: его вызывает АИС (ais-spa через /site-api).
     * Каждый маршрут требует СВОЕГО права сотрудника АИС — права хранятся
     * в БД `ais`, сотрудника backend читает оттуда же (App\Services\AisAuth),
     * а набор прав описан в shared/ais_permissions.php. Опасные операции
     * (возврат денег, удаления) там же помечены как требующие кода
     * подтверждения — его спрашивает то же middleware.
     */

    // Админский возврат (перенос ais/back_bilet.php).
    Route::post('admin/refunds/inspect', [AdminRefundController::class, 'inspect'])
        ->middleware('ais.can:back-ticket.inspect');
    Route::post('admin/refunds/confirm', [AdminRefundController::class, 'confirm'])
        ->middleware('ais.can:back-ticket.refund');
    // Возврат только багажа и возврат удержания — те же права, что у
    // возврата билета: операция с деньгами покупателя.
    Route::post('admin/refunds/baggage', [AdminRefundController::class, 'confirmBaggage'])
        ->middleware('ais.can:back-ticket.refund');
    Route::post('admin/refunds/penalty', [AdminRefundController::class, 'confirmPenalty'])
        ->middleware('ais.can:back-ticket.refund');
    Route::post('admin/refunds/repair', [AdminRefundController::class, 'repair'])
        ->middleware('ais.can:back-ticket.repair');
    /*
     * Досылка аннулирования на станцию по возврату, сделанному при её
     * недоступности (деньги покупателю уже вернули). Право — как у
     * возврата: операция меняет ведомость станции. Список ожидающих
     * доступен на просмотр.
     */
    Route::post('admin/refunds/annul', [AdminRefundController::class, 'annul'])
        ->middleware('ais.can:back-ticket.refund');
    Route::get('admin/refunds/pending', [AdminRefundController::class, 'pending'])
        ->middleware('ais.can:back-ticket.inspect');
    /*
     * Отмена платежа по заказу без билетов: деньги списаны, оформление не
     * состоялось. Право — как у возврата денег (операция с деньгами
     * покупателя), и с кодом подтверждения.
     */
    Route::post('admin/refunds/cancel-payment', [AdminRefundController::class, 'cancelPayment'])
        ->middleware('ais.can:back-ticket.refund');

    /*
     * Покупатели сайта: кабинеты и покупавшие без регистрации.
     * `assist` — помощь со входом (обратимо), `edit` — смена e-mail и
     * отвязка VK, `impersonate` — вход в кабинет от имени покупателя;
     * последние два помечены в каталоге прав как требующие кода.
     */
    Route::get('admin/customers', [AdminCustomerController::class, 'index'])
        ->middleware('ais.can:customers.view');
    Route::post('admin/customers/guest', [AdminCustomerController::class, 'guest'])
        ->middleware('ais.can:customers.view');
    Route::get('admin/customers/{customer}', [AdminCustomerController::class, 'show'])
        ->whereNumber('customer')->middleware('ais.can:customers.view');
    Route::post('admin/customers/{customer}/assist', [AdminCustomerController::class, 'assist'])
        ->whereNumber('customer')->middleware('ais.can:customers.assist');
    Route::put('admin/customers/{customer}', [AdminCustomerController::class, 'update'])
        ->whereNumber('customer')->middleware('ais.can:customers.edit');
    Route::post('admin/customers/{customer}/impersonate', [AdminCustomerController::class, 'impersonate'])
        ->whereNumber('customer')->middleware('ais.can:customers.impersonate');

    // Редактирование контактов сайта из админки АИС.
    Route::get('admin/contacts', [AdminContactController::class, 'index'])
        ->middleware('ais.can:contacts.view');
    Route::put('admin/contacts', [AdminContactController::class, 'update'])
        ->middleware('ais.can:contacts.edit');

    // Страницы, меню и файлы сайта — там же, из админки АИС.
    Route::get('admin/pages', [AdminPageController::class, 'index'])
        ->middleware('ais.can:pages.view');
    Route::post('admin/pages', [AdminPageController::class, 'store'])
        ->middleware('ais.can:pages.edit');
    Route::get('admin/pages/{page}', [AdminPageController::class, 'show'])
        ->middleware('ais.can:pages.view');
    Route::put('admin/pages/{page}', [AdminPageController::class, 'update'])
        ->middleware('ais.can:pages.edit');
    Route::delete('admin/pages/{page}', [AdminPageController::class, 'destroy'])
        ->middleware('ais.can:pages.delete');

    Route::get('admin/news', [AdminNewsController::class, 'index'])
        ->middleware('ais.can:news.view');
    Route::post('admin/news', [AdminNewsController::class, 'store'])
        ->middleware('ais.can:news.edit');
    Route::get('admin/news/{news}', [AdminNewsController::class, 'show'])
        ->middleware('ais.can:news.view');
    Route::put('admin/news/{news}', [AdminNewsController::class, 'update'])
        ->middleware('ais.can:news.edit');
    Route::delete('admin/news/{news}', [AdminNewsController::class, 'destroy'])
        ->middleware('ais.can:news.delete');

    // Немедленное обновление данных, которые сайт держит у себя
    // (в legacy кнопка перезаписи расписания была в getrasp.php).
    Route::get('admin/sync', [AdminDataSyncController::class, 'index'])
        ->middleware('ais.can:data-sync.view');
    Route::post('admin/sync/schedules', [AdminDataSyncController::class, 'syncSchedules'])
        ->middleware('ais.can:data-sync.run');
    Route::post('admin/sync/destinations', [AdminDataSyncController::class, 'syncDestinations'])
        ->middleware('ais.can:data-sync.run');

    Route::get('admin/settings', [AdminSettingController::class, 'index'])
        ->middleware('ais.can:settings.view');
    Route::put('admin/settings', [AdminSettingController::class, 'update'])
        ->middleware('ais.can:settings.edit');

    Route::get('admin/blocks', [AdminSiteBlockController::class, 'index'])
        ->middleware('ais.can:blocks.view');
    Route::put('admin/blocks', [AdminSiteBlockController::class, 'update'])
        ->middleware('ais.can:blocks.edit');

    Route::get('admin/menu', [AdminMenuController::class, 'index'])
        ->middleware('ais.can:menu.view');
    Route::put('admin/menu', [AdminMenuController::class, 'update'])
        ->middleware('ais.can:menu.edit');

    // Обращения с сайта и антиспам-словарь — там же, из админки АИС.
    Route::get('admin/feedback', [AdminFeedbackController::class, 'index'])
        ->middleware('ais.can:feedback.view');
    Route::get('admin/feedback/{feedback}', [AdminFeedbackController::class, 'show'])
        ->whereNumber('feedback')->middleware('ais.can:feedback.view');
    Route::put('admin/feedback/{feedback}', [AdminFeedbackController::class, 'update'])
        ->whereNumber('feedback')->middleware('ais.can:feedback.edit');
    Route::delete('admin/feedback/{feedback}', [AdminFeedbackController::class, 'destroy'])
        ->whereNumber('feedback')->middleware('ais.can:feedback.delete');

    Route::get('admin/spam-rules', [AdminSpamRuleController::class, 'index'])
        ->middleware('ais.can:spam-rules.view');
    Route::put('admin/spam-rules', [AdminSpamRuleController::class, 'update'])
        ->middleware('ais.can:spam-rules.edit');

    Route::get('admin/uploads', [AdminUploadController::class, 'index'])
        ->middleware('ais.can:uploads.view');
    Route::post('admin/uploads', [AdminUploadController::class, 'store'])
        ->middleware('ais.can:uploads.upload');
    Route::delete('admin/uploads', [AdminUploadController::class, 'destroy'])
        ->middleware('ais.can:uploads.delete');
});
