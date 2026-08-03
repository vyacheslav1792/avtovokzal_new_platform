<?php

/*
 * Клиенты KKM API: аутентификация Bearer-токеном + whitelist IP,
 * по образцу агентов sales-api.
 */

return [
    'list' => [
        [
            'id' => 'backend',
            'name' => 'API avtovokzal57 (backend)',
            'token' => env('KKM_CLIENT_BACKEND_TOKEN', 'dev-kkm-backend-token-please-change'),
            'ip_whitelist' => [
                '127.0.0.0/8',
                '10.0.0.0/8',
                '172.16.0.0/12',
                '192.168.0.0/16',
            ],
        ],
    ],
];
