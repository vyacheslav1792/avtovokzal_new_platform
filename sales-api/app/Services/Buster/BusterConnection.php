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
     * Проверка, что до станции вообще доходит TCP, — ДО создания PDO.
     *
     * Так приходится делать потому, что **pdo_firebird таймаут
     * подключения не поддерживает**: `PDO::ATTR_TIMEOUT` он молча
     * игнорирует (проверено — соединение с «чёрной дырой» висело больше
     * двух минут), а ждать оно будет столько, сколько ждёт системный
     * connect(), то есть около двух минут при потерянных пакетах. Всё это
     * время запрос занимает обработчик php-fpm: 16.11.2026 связь со всеми
     * станциями пропала на 13 минут, свободные обработчики кончились, и
     * сайт отвечал «Server Error» даже там, где станции не нужны
     * (печатная форма, PDF билетов в письме).
     *
     * Проба именно TCP, а не запрос к базе: разорванный канал виден уже
     * здесь, а «Firebird ответил отказом» (неверный пароль, нет базы) —
     * это уже ответ станции, его разбирает PDO обычным путём.
     *
     * Бросаем PDOException — тем самым отказ выглядит для вызывающего
     * так же, как любая другая недоступность базы, и GatewayFactory
     * превращает его в понятное «автостанция недоступна».
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
