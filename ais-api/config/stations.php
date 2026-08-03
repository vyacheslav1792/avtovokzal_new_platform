<?php

// Единый конфиг станций живёт в shared/stations.php монорепозитория
// (в контейнере — /var/www/shared, см. docker-compose volumes).
$shared = is_file('/var/www/shared/stations.php')
    ? '/var/www/shared/stations.php'
    : dirname(__DIR__, 2).'/shared/stations.php';

return require $shared;
