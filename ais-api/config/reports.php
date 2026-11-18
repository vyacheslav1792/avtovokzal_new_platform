<?php

use App\Reports\AgentsReport;
use App\Reports\SiteRefundsReport;
use App\Reports\SiteSalesReport;
use App\Reports\StationSalesReport;

// Реестр отчётов АИС: slug => класс. Добавить отчёт = класс + строка здесь.
return [
    'agents' => AgentsReport::class,
    'site-sales' => SiteSalesReport::class,
    'station-sales' => StationSalesReport::class,
    // История возвратов (замена legacy ais/in_back.php): билеты, багаж и
    // возвраты удержаний в одном списке.
    'site-refunds' => SiteRefundsReport::class,
];
