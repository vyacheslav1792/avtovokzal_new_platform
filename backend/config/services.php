<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    // API продаж — отдельный сервис (свои базы автостанций, свой журнал).
    'sales_api' => [
        'url' => env('SALES_API_URL', 'http://sales_web/api/v1'),
        'token' => env('SALES_API_TOKEN'),
        'timeout' => (int) env('SALES_API_TIMEOUT', 240),
    ],

    // Сбербанк-эквайринг (SDK voronkovich/sberbank-acquiring-client).
    'sberbank' => [
        // ТОЛЬКО схема и хост: SDK сам добавляет префикс '/payment/rest/'
        // (Client::execute), и адрес с путём даёт 404 — см. docs/DEPLOY.md.
        'api_uri' => env('SBERBANK_API_URI', 'https://3dsec.sberbank.ru'),
        'username' => env('SBERBANK_USERNAME'),
        'password' => env('SBERBANK_PASSWORD'),
        // Куда шлюз вернёт браузер после 3-DS. По умолчанию выводится из
        // адреса сайта (FRONTEND_URL ← SITE_URL корневого .env), чтобы адрес
        // не приходилось держать в двух переменных; SBERBANK_RETURN_URL
        // остаётся на случай, когда возврат нужен на другой адрес.
        'return_url' => env(
            'SBERBANK_RETURN_URL',
            rtrim((string) env('FRONTEND_URL', 'http://127.0.0.1:3000'), '/').'/payment/result',
        ),
        // Цепочка HTTPS Сбера; применяется только к его HTTP-клиенту.
        'ca_bundle' => env('SBERBANK_CA_BUNDLE', resource_path('certificates/russian_trusted_root_ca.pem')),
        /*
         * СКОЛЬКО ЖДЁМ ШЛЮЗ. Задать обязательно: у libcurl умолчание
         * CURLOPT_CONNECTTIMEOUT — 300 секунд, а SDK своих таймаутов не
         * ставит (CurlClient прокидывает ровно то, что ему дали).
         * У боевого хоста ДВА адреса (`3dsec-payments.yookassa.ru` →
         * 109.235.165.36 и 185.71.78.36), и когда один из них залипает —
         * пакеты уходят в пустоту, а не отказом, — мы висели ровно
         * 300 с: за 17.11.2026 так потеряны `POST orders` (504
         * покупателю, заказ остался pending) и `orders/confirm` по
         * оплаченному заказу, плюс пятиминутный простой
         * `orders:cancel-abandoned`.
         *
         * Границы выбраны так:
         *  - connect 10 с: нормальное подключение — 0,01–0,07 с, а
         *    libcurl при двух адресах делит этот предел между ними, то
         *    есть на залипший адрес уходит ~5 с и дальше пробуется
         *    второй;
         *  - всего 30 с: заведомо больше любого честного ответа шлюза
         *    (0,08–0,29 с) и заведомо МЕНЬШЕ 60 с `fastcgi_read_timeout`
         *    у nginx — иначе покупателю достаётся пустой 504 от nginx
         *    вместо нашего обработанного отказа, а обработчик php-fpm
         *    остаётся занят уже никому не нужным запросом.
         */
        'connect_timeout' => (int) env('SBERBANK_CONNECT_TIMEOUT', 10),
        'timeout' => (int) env('SBERBANK_TIMEOUT', 30),
    ],

    // Gotenberg — рендер PDF билетов из печатной формы фронта (Chromium).
    // print_base — адрес Nuxt, ПО КОТОРОМУ ХОДИТ GOTENBERG (внутренняя сеть
    // compose), а не адрес для браузера пользователя.
    'gotenberg' => [
        'url' => env('GOTENBERG_URL', 'http://gotenberg:3000'),
        'print_base' => env('PRINT_BASE_URL', 'http://frontend:3000'),
        /*
         * Ждём ЧУТЬ ДОЛЬШЕ, чем сам gotenberg (--api-timeout=60s в
         * compose): иначе мы отваливаемся по своему таймауту первыми и в
         * журнале остаётся «cURL error 28» вместо внятного ответа
         * gotenberg о том, что рендер не уложился в срок.
         */
        'timeout' => (int) env('GOTENBERG_TIMEOUT', 70),
    ],

    // KKM API — отдельный сервис-прослойка к KkmServer (фискальные чеки).
    'kkm_api' => [
        'url' => env('KKM_API_URL', 'http://kkm_web/api/v1'),
        'token' => env('KKM_API_TOKEN'),
        'timeout' => (int) env('KKM_API_TIMEOUT', 60),
    ],

];
