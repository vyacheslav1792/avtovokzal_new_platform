<?php

// Единые настройки авторизации сотрудников АИС живут в shared/ais_auth.php
// монорепозитория (в контейнере — /var/www/shared, см. docker-compose).
// Срок жизни сессии обязан совпадать у ais-api и backend — не дублировать.
$shared = is_file('/var/www/shared/ais_auth.php')
    ? '/var/www/shared/ais_auth.php'
    : dirname(__DIR__, 2).'/shared/ais_auth.php';

return require $shared;
