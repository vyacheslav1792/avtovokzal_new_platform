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
        'api_uri' => env('SBERBANK_API_URI', 'https://3dsec.sberbank.ru'),
        'username' => env('SBERBANK_USERNAME'),
        'password' => env('SBERBANK_PASSWORD'),
        'return_url' => env('SBERBANK_RETURN_URL'),
    ],

    // Gotenberg — рендер PDF билетов из печатной формы фронта (Chromium).
    // print_base — адрес Nuxt, ПО КОТОРОМУ ХОДИТ GOTENBERG (внутренняя сеть
    // compose), а не адрес для браузера пользователя.
    'gotenberg' => [
        'url' => env('GOTENBERG_URL', 'http://gotenberg:3000'),
        'print_base' => env('PRINT_BASE_URL', 'http://frontend:3000'),
        'timeout' => (int) env('GOTENBERG_TIMEOUT', 60),
    ],

    // KKM API — отдельный сервис-прослойка к KkmServer (фискальные чеки).
    'kkm_api' => [
        'url' => env('KKM_API_URL', 'http://kkm_web/api/v1'),
        'token' => env('KKM_API_TOKEN'),
        'timeout' => (int) env('KKM_API_TIMEOUT', 60),
    ],

];
