<?php

// Реестр отчётов АИС: slug => класс. Добавить отчёт = класс + строка здесь.
return [
    'agents' => \App\Reports\AgentsReport::class,
    'site-sales' => \App\Reports\SiteSalesReport::class,
];
