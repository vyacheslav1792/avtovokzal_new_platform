<?php

/*
 * ЕДИНЫЙ конфиг станций (автовокзалы/кассовые пункты) — перенос $AWT из
 * legacy avtovokzal57/inc/config.php (авторитетная копия; в legacy $AWT
 * дублировался трижды и копии разъехались). Используют sales-api и ais-api:
 * их config/stations.php делают `return require` этого файла (в контейнеры
 * монтируется как /var/www/shared/stations.php).
 *
 * Поля станции (ВСЕ значимы, не упрощать):
 *   name        — название на сайте/в отчётах
 *   db_host/db_port/db_name/db_user/db_password — база продаж (Firebird BUSTER)
 *   buh         — код станции для бухгалтерии (выгрузки)
 *   buh_st      — название станции в бухгалтерских документах
 *   buh_ksr     — наименование кассира в бухгалтерских документах
 *   stationkey  — ключ станции (аутентификация станционных обменов)
 *   inlist      — порядок в списках/отчётах
 *
 * В рабочем списке ТОЛЬКО реально развёрнутые базы: локально это Красная
 * Заря (контейнер firebird / alias buster). Разворачиваешь следующую базу —
 * раскомментируй её блок и проверь db_host/db_port (боевые значения ниже
 * сохранены из legacy: 127.0.0.1:432xx — zebedee-туннели боевого сервера,
 * прямые IP 192.168.4.x — станции в VPN).
 */

// Локальное dev-подключение (контейнер firebird с бэкапом Красной Зари).
$fbHost = env('FB_HOST', 'firebird');
$fbPort = (int) env('FB_PORT', 3050);
$fbDb = env('FB_DATABASE', 'buster');
$fbUser = env('FB_USERNAME', 'SYSDBA');
$fbPassword = env('FB_PASSWORD', 'masterkey');

// Хост zebedee-туннелей боевого сервера (для закомментированных станций).
$prodTunnelHost = env('STATIONS_TUNNEL_HOST', '127.0.0.1');

return [
    'default_pre_sale_days' => 10,

    'list' => [
        '5722' => [
            'name' => 'Красная Заря КП',
            'db_host' => $fbHost, 'db_port' => $fbPort, 'db_name' => $fbDb,
            'db_user' => $fbUser, 'db_password' => $fbPassword,
            // боевое подключение: $prodTunnelHost:43222
            'buh' => 'KRZ', 'buh_st' => 'КП Красная Заря', 'buh_ksr' => 'Кассир Красная Заря',
            'stationkey' => 'krasnzar_45jkljdf45', 'inlist' => 16,
        ],

        // ---- Ещё не развёрнутые станции (боевые реквизиты из legacy $AWT) ----
        // '5701' => ['name' => 'Орел АВ', 'db_host' => '192.168.4.50', 'db_port' => 3050, 'db_name' => 'buster', 'db_user' => 'SYSDBA', 'db_password' => '1',
        //     'buh' => 'ORL', 'buh_st' => 'АВ Орел', 'buh_ksr' => 'кассир Орел', 'stationkey' => 'orel_3434354erjewhrg', 'inlist' => 1],
        // '5705' => ['name' => 'Ливны АС', 'db_host' => $prodTunnelHost, 'db_port' => 43205, 'db_name' => 'buster', 'db_user' => 'SYSDBA', 'db_password' => '1',
        //     'buh' => 'LIV', 'buh_st' => 'АС Ливны', 'buh_ksr' => 'Кассир Ливны', 'stationkey' => 'livn_hfghgfh54erjewhrg', 'inlist' => 2],
        // '5706' => ['name' => 'Мценск АС', 'db_host' => $prodTunnelHost, 'db_port' => 43206, 'db_name' => 'buster', 'db_user' => 'SYSDBA', 'db_password' => '1',
        //     'buh' => 'MTS', 'buh_st' => 'Остановочный пункт  Мценск', 'buh_ksr' => 'Кассир Мценск', 'stationkey' => 'mcensk_224hfjfghgdfgdfg', 'inlist' => 3],
        // '5707' => ['name' => 'Кромы АС', 'db_host' => $prodTunnelHost, 'db_port' => 43207, 'db_name' => 'buster', 'db_user' => 'SYSDBA', 'db_password' => '1',
        //     'buh' => 'KRO', 'buh_st' => 'Остановочный пункт  Кромы', 'buh_ksr' => 'Кассир Кромы', 'stationkey' => 'krom_hfghgfh54erjewhrg', 'inlist' => 4],
        // '5712' => ['name' => 'Болхов КП', 'db_host' => $prodTunnelHost, 'db_port' => 43212, 'db_name' => 'buster', 'db_user' => 'SYSDBA', 'db_password' => '1',
        //     'buh' => 'BOL', 'buh_st' => 'КП Болхов', 'buh_ksr' => 'Кассир Болхов', 'stationkey' => 'bolhov_dfhrgffgfg', 'inlist' => 5],
        // '5715' => ['name' => 'Дмитровск КП', 'db_host' => $prodTunnelHost, 'db_port' => 43215, 'db_name' => 'buster', 'db_user' => 'SYSDBA', 'db_password' => '1',
        //     'buh' => 'DMI', 'buh_st' => 'КП Дмитровск', 'buh_ksr' => 'Кассир Дмитровск', 'stationkey' => 'dmitr_fgfdgdfg', 'inlist' => 6],
        // '5714' => ['name' => 'Верховье КП', 'db_host' => $prodTunnelHost, 'db_port' => 43214, 'db_name' => 'buster', 'db_user' => 'SYSDBA', 'db_password' => '1',
        //     'buh' => 'VER', 'buh_st' => 'КП Верховье', 'buh_ksr' => 'Кассир Верховье', 'stationkey' => 'verch_dgfgdfgdfgfdg', 'inlist' => 7],
        // '5720' => ['name' => 'Тросна КП', 'db_host' => $prodTunnelHost, 'db_port' => 43220, 'db_name' => 'buster', 'db_user' => 'SYSDBA', 'db_password' => '1',
        //     'buh' => 'TRO', 'buh_st' => 'КП Тросна', 'buh_ksr' => 'Кассир Тросна', 'stationkey' => 'trosn_guyth54erjfgrg', 'inlist' => 8],
        // '5721' => ['name' => 'Новосиль КП', 'db_host' => '192.168.4.54', 'db_port' => 3050, 'db_name' => 'buster', 'db_user' => 'SYSDBA', 'db_password' => '1',
        //     'buh' => 'NOV', 'buh_st' => 'КП Новосиль', 'buh_ksr' => 'Кассир Новосиль', 'stationkey' => 'novosil_fgfdgdfgd', 'inlist' => 9],
        // '5713' => ['name' => 'Хомутово КП', 'db_host' => $prodTunnelHost, 'db_port' => 43213, 'db_name' => 'buster', 'db_user' => 'SYSDBA', 'db_password' => '1',
        //     'buh' => 'XOM', 'buh_st' => 'КП Хомутово', 'buh_ksr' => 'Кассир Хомутово', 'stationkey' => 'homut_434hfghgfdfh54erjewhrg', 'inlist' => 11],
        // '5708' => ['name' => 'Колпны КП', 'db_host' => $prodTunnelHost, 'db_port' => 43208, 'db_name' => 'buster', 'db_user' => 'SYSDBA', 'db_password' => '1',
        //     'buh' => 'KOL', 'buh_st' => 'КП Колпны', 'buh_ksr' => 'Кассир Колпны', 'stationkey' => 'kolp_hgjghjgf54erjewhrg', 'inlist' => 12],
        // Долгое: в legacy две записи '5719', действующая — прямой IP (перекрывала туннель 43219).
        // '5719' => ['name' => 'Долгое КП', 'db_host' => '192.168.4.138', 'db_port' => 3050, 'db_name' => 'buster', 'db_user' => 'SYSDBA', 'db_password' => '1',
        //     'buh' => 'DOL', 'buh_st' => 'КП Долгое', 'buh_ksr' => 'Кассир Долгое', 'stationkey' => 'dolg_qwqwetjgdgzxdsf44', 'inlist' => 13],
        // '5717' => ['name' => 'Малоархангельск КП', 'db_host' => $prodTunnelHost, 'db_port' => 43217, 'db_name' => 'buster', 'db_user' => 'SYSDBA', 'db_password' => '1',
        //     'buh' => 'MAL', 'buh_st' => 'КП М-Архангельск', 'buh_ksr' => 'Кассир М-Архангельск', 'stationkey' => 'maloarh_fhfdhfdhfdsdfs', 'inlist' => 14],
        // '5716' => ['name' => 'Залегощь КП', 'db_host' => $prodTunnelHost, 'db_port' => 43216, 'db_name' => 'buster', 'db_user' => 'SYSDBA', 'db_password' => '1',
        //     'buh' => 'ZAL', 'buh_st' => 'КП Залегощь', 'buh_ksr' => 'Кассир Залегощь', 'stationkey' => 'zaleg_gfhfghgfhf', 'inlist' => 15],
        // '5724' => ['name' => 'Шаблыкино КП', 'db_host' => $prodTunnelHost, 'db_port' => 43224, 'db_name' => 'buster', 'db_user' => 'SYSDBA', 'db_password' => '1',
        //     'buh' => 'SHA', 'buh_st' => 'КП Шаблыкино', 'buh_ksr' => 'Кассир Шаблыкино', 'stationkey' => 'shabl_pooipuiret1234', 'inlist' => 17],
        // '5703' => ['name' => 'Дросково КП', 'db_host' => '192.168.4.53', 'db_port' => 3050, 'db_name' => 'buster', 'db_user' => 'SYSDBA', 'db_password' => '1',
        //     'buh' => 'DRO', 'buh_st' => 'КП Дросково', 'buh_ksr' => 'Кассир Дросково', 'stationkey' => 'drosk_sadsadadsasd', 'inlist' => 18],
        // '5723' => ['name' => 'Сосково КП', 'db_host' => $prodTunnelHost, 'db_port' => 43223, 'db_name' => 'buster', 'db_user' => 'SYSDBA', 'db_password' => '1',
        //     'buh' => 'SOS', 'buh_st' => 'КП Сосково', 'buh_ksr' => 'Кассир Сосково', 'stationkey' => 'sosk_jhkdfgdfgdfgdf', 'inlist' => 20],
        // Змиевка: в legacy действующая запись ходит через порт 43211 (бывший туннель Знаменского) — сохранено как есть.
        // '5702' => ['name' => 'Змиевка КП', 'db_host' => $prodTunnelHost, 'db_port' => 43211, 'db_name' => 'buster', 'db_user' => 'SYSDBA', 'db_password' => '1',
        //     'buh' => 'ZME', 'buh_st' => 'КП Змиевка', 'buh_ksr' => 'Кассир Змиевка', 'stationkey' => 'zmievk_fdgfdgfdg', 'inlist' => 21],

        // ---- Выключены и в legacy ----
        // '5718' => ['name' => 'Нарышкино КП', 'db_host' => $prodTunnelHost, 'db_port' => 43218, 'db_name' => 'buster', 'db_user' => 'SYSDBA', 'db_password' => '1',
        //     'buh' => 'NAR', 'buh_st' => 'КП Нарышкино', 'buh_ksr' => 'Кассир Нарышкино', 'stationkey' => 'nar_fgfdgdfsdfsdf', 'inlist' => 10],
        // '5711' => ['name' => 'Знаменское КП', 'db_host' => $prodTunnelHost, 'db_port' => 43211, 'db_name' => 'buster', 'db_user' => 'SYSDBA', 'db_password' => '1',
        //     'buh' => 'ZNA', 'buh_st' => 'КП Знаменское', 'buh_ksr' => 'Кассир Знаменское', 'stationkey' => 'znamenskoe_fgfdgsdfg', 'inlist' => 19],
    ],
];
