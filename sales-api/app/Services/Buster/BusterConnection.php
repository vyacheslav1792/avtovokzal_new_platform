<?php

namespace App\Services\Buster;

use PDO;

/**
 * Соединение с вендорной базой продаж (Firebird 2.5, BUSTER) одной станции.
 *
 * Ловушки, унаследованные от legacy (docs/LEGACY.md §13):
 *  - транзакции ЯВНЫЕ: резерв/продажа не видны без commit;
 *  - charset=UTF8 в DSN — Firebird сам конвертирует WIN1251;
 *  - TIMESTAMP с долями секунды (ID_TIME_PLACES) нельзя биндить параметром —
 *    доли секунды теряются; такие значения инлайнятся в SQL литералом
 *    (см. quoteTimestamp()).
 */
class BusterConnection
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

    /** SELECT → массив ассоц. массивов (аналог db_work->inArray). */
    public function select(string $sql, array $bindings = []): array
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($bindings);

        $rows = $stmt->fetchAll();

        // Firebird отдаёт CHAR с хвостовыми пробелами — подчищаем.
        return array_map(
            fn (array $row) => array_map(
                fn ($v) => is_string($v) ? rtrim($v) : $v,
                $row,
            ),
            $rows,
        );
    }

    public function execute(string $sql, array $bindings = []): int
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($bindings);

        return $stmt->rowCount();
    }

    public function begin(): void
    {
        if (! $this->pdo->inTransaction()) {
            $this->pdo->beginTransaction();
        }
    }

    public function commit(): void
    {
        if ($this->pdo->inTransaction()) {
            $this->pdo->commit();
        }
    }

    public function rollBack(): void
    {
        if ($this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }

    /**
     * Timestamp-литерал для инлайна в SQL (только после валидации формата!).
     * Firebird теряет доли секунды при биндинге строк в TIMESTAMP-параметры.
     */
    public static function quoteTimestamp(string $value): string
    {
        if (! preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}(\.\d{1,4})?$/', $value)) {
            throw new \InvalidArgumentException("Некорректный формат timestamp: {$value}");
        }

        return "'".$value."'";
    }
}
