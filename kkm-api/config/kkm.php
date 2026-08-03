<?php

// Подключение к KkmServer (https://kkmserver.ru), установлен на хост-машине.
return [
    'url' => env('KKM_SERVER_URL', 'http://host.docker.internal:5893'),
    'user' => env('KKM_SERVER_USER', ''),
    'password' => env('KKM_SERVER_PASSWORD', ''),

    // Номер устройства ККТ в KkmServer (0 — первое свободное).
    'num_device' => (int) env('KKM_NUM_DEVICE', 1),

    // Сколько секунд KkmServer ждёт выполнения команды.
    'command_timeout' => (int) env('KKM_COMMAND_TIMEOUT', 30),

    'cashier_name' => env('KKM_CASHIER_NAME', 'Сайт автовокзал57.рф'),
];
