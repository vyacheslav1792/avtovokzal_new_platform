<?php

// Реестр отчётов АИС: slug => класс. Добавить отчёт = класс + строка здесь.
return [
    'agents' => \App\Reports\AgentsReport::class,
    'site-sales' => \App\Reports\SiteSalesReport::class,
    // История возвратов (замена legacy ais/in_back.php): билеты, багаж и
    // возвраты удержаний в одном списке.
    'site-refunds' => \App\Reports\SiteRefundsReport::class,
];
