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

    // Кассовый сервер (фискальные чеки).
    'kkm' => [
        'url' => env('KKM_SERVER_URL'),
        'key' => env('KKM_SERVER_KEY'),
    ],

];
