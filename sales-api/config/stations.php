<?php

/*
 * Станции (автовокзалы/кассовые пункты) и подключения к их вендорным базам
 * продаж (Firebird, BUSTER). Перенос $AWT из legacy avtovokzal57/inc/config.php.
 *
 * Локально ВСЕ станции указывают на контейнер firebird (alias buster) —
 * реально восстановлена только база Красной Зари (5722). В прод-окружении
 * host/port задаются реальными адресами станций.
 */

$fbHost = env('FB_HOST', 'firebird');
$fbPort = (int) env('FB_PORT', 3050);
$fbDb = env('FB_DATABASE', 'buster');
$fbUser = env('FB_USERNAME', 'SYSDBA');
$fbPassword = env('FB_PASSWORD', 'masterkey');

$station = fn (string $name) => [
    'name' => $name,
    'db_host' => $fbHost,
    'db_port' => $fbPort,
    'db_name' => $fbDb,
    'db_user' => $fbUser,
    'db_password' => $fbPassword,
];

return [
    'default_pre_sale_days' => 10,

    'list' => [
        '5701' => $station('Орел АВ'),
        '5702' => $station('Змиевка КП'),
        '5703' => $station('Дросково КП'),
        '5705' => $station('Ливны АС'),
        '5706' => $station('Мценск АС'),
        '5707' => $station('Кромы АС'),
        '5708' => $station('Колпны КП'),
        '5712' => $station('Болхов КП'),
        '5713' => $station('Хомутово КП'),
        '5714' => $station('Верховье КП'),
        '5715' => $station('Дмитровск КП'),
        '5716' => $station('Залегощь КП'),
        '5717' => $station('Малоархангельск КП'),
        '5719' => $station('Долгое КП'),
        '5720' => $station('Тросна КП'),
        '5721' => $station('Новосиль КП'),
        '5722' => $station('Красная Заря КП'),
        '5723' => $station('Сосково КП'),
        '5724' => $station('Шаблыкино КП'),
    ],
];
