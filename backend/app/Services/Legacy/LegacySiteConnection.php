<?php

namespace App\Services\Legacy;

use PDO;
use PDOStatement;

/**
 * Соединение со старой базой сайта (Firebird 2.5, site.fdb): ORDERS, BILETS,
 * BACKS, EMAIL, USERS. Нужно только команде переноса `legacy:import-site`.
 *
 * charset=UTF8 в DSN — Firebird сам конвертирует WIN1251, iconv не нужен
 * (как в sales-api/ais-api). BLOB-поля (BUYERS, BILETS, ATP, COMMENT)
 * pdo_firebird отдаёт ресурсом — читаем их через blob().
 */
class LegacySiteConnection
{
    private PDO $pdo;

    public function __construct(?array $config = null)
    {
        $config ??= config('legacy_site');

        $dsn = sprintf(
            'firebird:dbname=%s/%d:%s;charset=UTF8',
            $config['host'],
            $config['port'],
            $config['database'],
        );

        $this->pdo = new PDO($dsn, $config['username'], $config['password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    }

    /** Курсор по выборке: строки читаются по одной, вся таблица в память не поднимается. */
    public function cursor(string $sql, array $bindings = []): PDOStatement
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($bindings);

        return $stmt;
    }

    public function scalar(string $sql, array $bindings = []): mixed
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($bindings);

        return $stmt->fetchColumn();
    }

    /** BLOB → строка (pdo_firebird отдаёт их потоком). */
    public static function blob(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_resource($value)) {
            $content = stream_get_contents($value);

            return $content === false ? null : $content;
        }

        return (string) $value;
    }
}
