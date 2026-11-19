<?php

return [
    /*
     * Откуда забирать списки исключений отчёта по нулевым билетам при
     * переносе со старого АИС (команда lgot:import-legacy): каталог с
     * файлами `ATP_ISKLUCH_PRIG.txt`, `TICKET_ISKLUCH_PRIG.txt` и
     * `ATP_ISKLUCH.txt` либо адрес старого АИС — в его корне они и лежат.
     */
    'source' => env('LEGACY_AIS_URL', 'http://192.168.2.5'),
];
