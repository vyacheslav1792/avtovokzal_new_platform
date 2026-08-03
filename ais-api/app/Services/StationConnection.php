<?php

namespace App\Services;

use PDO;

/**
 * Read-only соединение с вендорной базой продаж станции (Firebird 2.5,
 * BUSTER) для отчётов. charset=UTF8 в DSN — Firebird сам конвертирует
 * WIN1251, iconv из legacy не нужен.
 */
class StationConnection
{
    private PDO $pdo;

    public function __construct(array $station)
    {
        $dsn = sprintf(
            'firebird:dbname=%s/%d:%s;charset=UTF8',
            $station['db_host'],
            $station['db_port'],
            $station['db_name'],
        );

        $this->pdo = new PDO($dsn, $station['db_user'], $station['db_password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    }

    /** SELECT → массив ассоц. массивов; CHAR подчищается от хвостовых пробелов. */
    public function select(string $sql, array $bindings = []): array
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($bindings);

        return array_map(
            fn (array $row) => array_map(
                fn ($v) => is_string($v) ? rtrim($v) : $v,
                $row,
            ),
            $stmt->fetchAll(),
        );
    }
}
