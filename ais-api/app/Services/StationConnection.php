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

    /**
     * @param  int  $connectTimeout  сколько секунд ждать связи (0 — не ограничивать)
     */
    public function __construct(array $station, int $connectTimeout = 0)
    {
        if ($connectTimeout > 0) {
            self::assertReachable(
                (string) $station['db_host'],
                (int) $station['db_port'],
                $connectTimeout,
            );
        }

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

    /**
     * Проверка, что до станции доходит TCP, — ДО создания PDO.
     *
     * **pdo_firebird таймаут подключения не поддерживает**:
     * `PDO::ATTR_TIMEOUT` он молча игнорирует (проверено — соединение с
     * «чёрной дырой» висело больше двух минут), поэтому при пропавшем
     * канале отчёт или кнопка «Проверить» в справочнике станций ждут
     * системный connect() — около двух минут. Тот же приём и по той же
     * причине есть в API продаж (`BusterConnection::assertReachable`).
     *
     * Проба именно TCP: разорванный канал виден уже здесь, а отказ самой
     * базы (неверный пароль, нет базы) — это ответ станции, его разбирает
     * PDO обычным путём.
     */
    private static function assertReachable(string $host, int $port, int $timeout): void
    {
        $socket = @stream_socket_client(
            "tcp://{$host}:{$port}",
            $errno,
            $error,
            $timeout,
            STREAM_CLIENT_CONNECT,
        );

        if ($socket !== false) {
            fclose($socket);

            return;
        }

        throw new \PDOException(sprintf(
            'нет связи с %s:%d за %d с (%s)',
            $host,
            $port,
            $timeout,
            $error !== '' ? $error : 'код '.$errno,
        ));
    }

    public function readSnapshot(callable $read): mixed
    {
        $this->pdo->beginTransaction();
        try {
            return $read();
        } finally {
            $this->pdo->rollBack();
        }
    }

    /** SELECT → массив ассоц. массивов; CHAR подчищается от хвостовых пробелов. */
    public function select(string $sql, array $bindings = [], ?string $binaryColumn = null): array
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($bindings);

        $rows = [];
        while ($row = $stmt->fetch()) {
            if ($binaryColumn !== null) {
                $row[$binaryColumn] = bin2hex($row[$binaryColumn]);
            }
            $rows[] = array_map(fn ($v) => is_string($v) ? rtrim($v) : $v, $row);
        }

        return $rows;
    }
}
