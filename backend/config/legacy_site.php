<?php

/*
 * Старая база сайта avtovokzal57 (Firebird 2.5, site.fdb) — источник для
 * разовой команды переноса истории покупок `legacy:import-site`.
 * Читаем её ТОЛЬКО на чтение: боевые данные живут своей жизнью, пока
 * старый сайт не выключен.
 */

return [
    'host' => env('LEGACY_SITE_DB_HOST', 'firebird'),
    'port' => (int) env('LEGACY_SITE_DB_PORT', 3050),
    'database' => env('LEGACY_SITE_DB_NAME', 'site'),
    'username' => env('LEGACY_SITE_DB_USER', 'SYSDBA'),
    'password' => env('LEGACY_SITE_DB_PASSWORD', 'masterkey'),

    /*
     * Соль хэширования паролей старого сайта: legacy хранил
     * md5(соль + пароль) в `USERS.PASSWORD` (`$CONFIG['password_sol']` в
     * `avtovokzal57/inc/config.php`). Нужна в двух местах — при переносе
     * кабинетов (`legacy:import-site`) и при входе прежним паролем
     * (AuthService::login).
     *
     * В репозиторий значение НЕ кладём (боевой секрет; сам legacy-код
     * в git тоже не хранится) — только в `backend/.env`. Пусто = вход
     * прежним паролем выключен, перенос кабинетов откажется работать.
     */
    'password_salt' => (string) env('LEGACY_SITE_PASSWORD_SALT', ''),
];
