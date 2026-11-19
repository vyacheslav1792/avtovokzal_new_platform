<?php

namespace App\Services\Buster;

use App\Exceptions\BusterException;
use App\Exceptions\StationUnavailableException;
use App\Support\VendorText;
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
     * Код и название станции — нужны, чтобы обрыв связи ПОСРЕДИ запроса
     * превратить во внятный отказ «автостанция недоступна», а не в
     * «Server Error» (см. assertAlive).
     */
    private int $awtcod;

    private string $stationName;

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

        $this->awtcod = (int) ($station['awtcod'] ?? 0);
        $this->stationName = (string) ($station['name'] ?? $this->awtcod);
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
        return $this->guard(function () use ($sql, $bindings) {
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
        });
    }

    public function execute(string $sql, array $bindings = []): int
    {
        return $this->guard(function () use ($sql, $bindings) {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($bindings);

            return $stmt->rowCount();
        });
    }

    /**
     * ОБРЫВ СВЯЗИ ПОСРЕДИ ЗАПРОСА — это «станция недоступна», а не сбой
     * приложения.
     *
     * `GatewayFactory` ловит `PDOException` только при СОЗДАНИИ
     * соединения: тогда получается понятный отказ 503 с кодом
     * `station_unavailable`, который сайт показывает покупателю словами и
     * не пишет в журнал ошибок. А если канал до станции рвётся уже после
     * подключения, PDO бросал исключение прямо отсюда — и покупатель
     * получал «Server Error».
     *
     * Ловилось на проде 17.11.2026 в 06:04: запрос рейсов к станции
     * «Болхов КП» висел 19 секунд и упал с `-902 Error reading data from
     * the connection`. Сбой был единичный (соседние запросы шли по
     * 170–620 мс, через 2,5 минуты всё отвечало снова), но покупателю об
     * этом сказать было нечем.
     *
     * Распознаём по КЛАССУ SQLSTATE «08» (connection exception): 08006
     * «connection failure» (Firebird -902), 08003 «connection does not
     * exist», 08001, 08S01.
     *
     * Второй разбираемый случай — значение параметра, которое базе
     * станции не подходит (символ не из WIN1251, строка шире поля): это
     * ввод, а не сбой, см. {@see isBadParameterValue}. Остальные ошибки —
     * наши или станционные, они идут наверх как есть и в журнал
     * попадают со стектрейсом.
     *
     * @template T
     *
     * @param  callable(): T  $query
     * @return T
     */
    private function guard(callable $query): mixed
    {
        try {
            return $query();
        } catch (\PDOException $e) {
            if (self::isConnectionLost($e)) {
                throw new StationUnavailableException(
                    $this->awtcod,
                    $this->stationName,
                    $e->getMessage(),
                );
            }

            if (self::isBadParameterValue($e)) {
                throw new BusterException(
                    'Значение в запросе не подходит базе станции.',
                    'bad_station_value',
                    ['sqlstate' => (string) $e->getCode(), 'reason' => mb_substr($e->getMessage(), 0, 200)],
                );
            }

            throw $e;
        }
    }

    /**
     * ЗНАЧЕНИЕ ПАРАМЕТРА БАЗЕ НЕ ПОДХОДИТ — это ввод, а не сбой
     * приложения, и отвечать на него надо отказом, а не «Server Error».
     *
     * Откуда берётся. База станции живёт в WIN1251, а соединение открыто
     * в UTF8 (как и в legacy), то есть параметры конвертирует сам
     * Firebird — и на символе, которого в WIN1251 нет, отвечает
     * **-802 «Cannot transliterate character between character sets»**
     * (SQLSTATE 22018), а на строке шире поля — **-303 «String data,
     * right truncated»** (22001); невалидный UTF-8 в параметре даёт
     * «Malformed string» (22000).
     *
     * Ловилось на проде 19.11.2026 в 19:15: обходчик Baidu открыл
     * `/trips?awtcod=5706&from=…&to=…` с названиями остановок в ДВОЙНОЙ
     * кодировке UTF-8 («Металлург дв» приехало как
     * `%C3%90%C2%9C%C3%90%C2%B5…»), Firebird ответил -802 — и один
     * мусорный запрос дал две записи ERROR со стектрейсами: здесь и в
     * backend («Server Error» от API продаж).
     *
     * Почему проверки на входе не хватает и нужна эта, вторая линия:
     * ширина `TRACES.STATNAME` у станций РАЗНАЯ (50 у Орла, 20 у
     * Залегощи, см. {@see VendorText::MAX_STATION_NAME}),
     * то есть одна и та же длина для одной станции допустима, а для
     * другой — ошибка. Знать это заранее валидация не может.
     *
     * Отказ отдаётся как прикладной (`BusterException` → 422 с кодом
     * `bad_station_value`), поэтому в журнал идёт одна строка notice без
     * стектрейса, а SQLSTATE и текст драйвера остаются в `details` — то
     * есть в ответе и в журнале запросов `api_requests`, по которым
     * разбирают случившееся.
     */
    private static function isBadParameterValue(\PDOException $e): bool
    {
        $state = (string) $e->getCode();
        $text = $e->getMessage();

        return match ($state) {
            // -802: символа нет в WIN1251 (транслитерация невозможна).
            '22018' => true,
            // -303: строка шире поля базы.
            '22001' => true,
            /*
             * 22000 — общее «Data exception», поэтому только по тексту:
             * сюда приходит «Malformed string» (невалидный UTF-8 в
             * параметре), а не любая ошибка данных.
             */
            '22000' => stripos($text, 'Malformed string') !== false,
            default => stripos($text, 'Cannot transliterate') !== false,
        };
    }

    /** Исключение PDO говорит об обрыве соединения, а не об ошибке запроса? */
    private static function isConnectionLost(\PDOException $e): bool
    {
        $state = (string) $e->getCode();

        if (str_starts_with($state, '08')) {
            return true;
        }

        // Страховка на случай, когда драйвер не выставил SQLSTATE.
        $text = $e->getMessage();

        return str_contains($text, '-902')
            || stripos($text, 'Error reading data from the connection') !== false
            || stripos($text, 'Error writing data to the connection') !== false
            || stripos($text, 'connection lost to database') !== false
            || stripos($text, 'Unable to complete network request') !== false;
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
        if (! $this->pdo->inTransaction()) {
            return;
        }

        try {
            $this->pdo->rollBack();
        } catch (\PDOException $e) {
            /*
             * Откат на оборванном соединении сам бросает исключение — и
             * подменяет им исходную ошибку, из-за которой откат и
             * понадобился. Сервер такую транзакцию всё равно отменит сам,
             * поэтому молчим: наверх должна уйти настоящая причина.
             */
            if (! self::isConnectionLost($e)) {
                throw $e;
            }
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
