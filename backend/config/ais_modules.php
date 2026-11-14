<?php

// Единый каталог модулей АИС и их прав живёт в shared/ais_permissions.php
// монорепозитория (в контейнере — /var/www/shared, см. docker-compose).
// Его же читает backend для своих /api/v1/admin/* — не дублировать.
$shared = is_file('/var/www/shared/ais_permissions.php')
    ? '/var/www/shared/ais_permissions.php'
    : dirname(__DIR__, 2).'/shared/ais_permissions.php';

return require $shared;
