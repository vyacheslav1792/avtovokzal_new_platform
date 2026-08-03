<?php

/*
 * Агенты API продаж. Перенос $AGENTS из legacy ais/api/config.php:
 * агент аутентифицируется Bearer-токеном, дополнительно действует
 * whitelist IP (CIDR-подсети). legacy_id — код агента в БД продаж
 * (AWTSALECOD/AGENT_CODE в процедурах KS_*).
 */

return [
    'list' => [
        // Сайт avtovokzal57 (высокоуровневое API)
        [
            'id' => 'site',
            'name' => 'Сайт автовокзал57.рф',
            'legacy_id' => 9984,
            'worker_code' => 990,
            'baggage_worker_code' => 991,
            'token' => env('SALES_AGENT_SITE_TOKEN', 'dev-site-token-please-change'),
            'ip_whitelist' => [
                '127.0.0.0/8',
                '10.0.0.0/8',
                '172.16.0.0/12',
                '192.168.0.0/16',
            ],
        ],
    ],
];
