<?php

return [
    // Значение до первого сохранения /settings. Рабочее окно — из settings.
    'default_lookback_days' => 90,
    'chunk_days' => 10,
    'legacy_page_size' => 1000,
    // Firebird 2.5 / PDO не умеют надёжно ограничивать время запроса.
    // Каждая порция читается дочерним CLI-процессом с жёстким дедлайном.
    'page_timeout' => 25,
    'lease_seconds' => 120,
    // Разовый перенос прежнего SALE_ALL, соединение только для чтения.
    'legacy' => [
        'db_host' => env('LEGACY_AIS_DB_HOST', 'firebird'),
        'db_port' => (int) env('LEGACY_AIS_DB_PORT', 3050),
        'db_name' => env('LEGACY_AIS_DB_NAME', 'ais'),
        'db_user' => env('LEGACY_AIS_DB_USER', 'SYSDBA'),
        'db_password' => env('LEGACY_AIS_DB_PASSWORD', 'masterkey'),
    ],
];
