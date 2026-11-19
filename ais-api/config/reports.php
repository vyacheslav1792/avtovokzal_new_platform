<?php

use App\Reports\AgentsReport;
use App\Reports\LgotReport;
use App\Reports\SiteRefundsReport;
use App\Reports\SiteSalesReport;
use App\Reports\StationSalesReport;

// Реестр отчётов АИС: slug => класс. Добавить отчёт = класс + строка здесь.
return [
    'agents' => AgentsReport::class,
    'site-sales' => SiteSalesReport::class,
    'station-sales' => StationSalesReport::class,
    // Нулевые билеты (перенос ТРЁХ страниц legacy — lgot_prig.php, lgot2.php
    // и lgot.php): проездные и социальные, уехавшие за период, — к
    // возмещению от АТП. Вид рейса выбирается фильтром, у отчёта своя
    // страница в SPA (meta.view), см. класс.
    'lgot' => LgotReport::class,
    // История возвратов (замена legacy ais/in_back.php): билеты, багаж и
    // возвраты удержаний в одном списке.
    'site-refunds' => SiteRefundsReport::class,
];
