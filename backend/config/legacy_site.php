<?php

/*
 * Старая база сайта avtovokzal57 (Firebird 2.5, site.fdb) — источник для
 * разовой команды переноса истории покупок `legacy:import-site`.
 * Читаем её ТОЛЬКО на чтение: боевые данные живут своей жизнью, пока
 * старый сайт не выключен.
 */

return [
    'host' => env('LEGACY_SITE_DB_HOST', 'firebird'),
    'port' => (int) env('LEGACY_SITE_DB_PORT', 3050),
    'database' => env('LEGACY_SITE_DB_NAME', 'site'),
    'username' => env('LEGACY_SITE_DB_USER', 'SYSDBA'),
    'password' => env('LEGACY_SITE_DB_PASSWORD', 'masterkey'),
];
