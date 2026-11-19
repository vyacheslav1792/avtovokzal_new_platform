<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/** Только чтение SALE; ни процедур продажи, ни изменений в базе станции. */
class StationSalesSource
{
    public const KEY = ['DATESALE', 'TIMESALE', 'AWTCODSALE', 'WRKCOD', 'TYPE_OPER', 'N_TICKET', 'DOB_N_TICKET', 'DATESTART'];

    public function __construct(private readonly StationRegistry $stations, private readonly SiteSettings $settings) {}

    private const REPORT_FIELDS = ['AWTCOD', 'KKM_OK', 'TICK_BACK', 'PRICE_PLACE', 'PRICE_BAG', 'PRICE_PL_ISX', 'PRICE_BAG_ISX'];

    private const DETAIL_FIELDS = "s.*, CAST(s.TIMESALE AS VARCHAR(20)) AS TIMESALE,
        (SELECT n.napname FROM naplst n WHERE n.napcod = s.napcod) AS NAPNAME,
        (SELECT FIRST 1 t.statname FROM traces t WHERE t.napcod = s.napcod AND t.statcod = s.nomstat) AS STATNAME";

    public function page(int $stationCode, string $day, ?array $cursor, int $limit, string $mode): array
    {
        $station = $this->stations->all()[$stationCode] ?? null;
        if (! $station) {
            throw new InvalidArgumentException('Станция отключена или не настроено подключение.');
        }
        $connection = new StationConnection($station, min(5, $this->settings->stationConnectTimeout()));

        if ($mode === 'full') {
            [$sql, $bindings] = self::query($day, $cursor, $limit, full: true);
            $rows = $connection->select($sql, $bindings);

            return self::pageResult($rows, [], end($rows));
        }

        // Физический DB_KEY используется только внутри одного снимка Firebird.
        // Между страницами и запусками используются логические ключи SALE.
        return $connection->readSnapshot(function () use ($connection, $stationCode, $day, $cursor, $limit) {
            [$sql, $bindings] = self::query($day, $cursor, $limit);
            $manifest = $connection->select($sql, $bindings, 'ROW_ID');
            $keys = array_map(self::recordKey(...), $manifest);
            $known = DB::table('station_sales')->where('source_station', $stationCode)->whereIn('record_key', $keys)
                ->pluck('report_hash', 'record_key')->all();
            $unchanged = $changed = [];
            foreach ($manifest as $i => $item) {
                if (($known[$keys[$i]] ?? null) === self::reportHash($item)) {
                    $unchanged[] = $keys[$i];
                } else {
                    $changed[] = $item;
                }
            }

            $rows = [];
            // NOM_TICK ограничивает поиск билетов, DB_KEY выбирает точную версию
            // бланка внутри снимка. Размер SQL ограничен лимитом BLR Firebird 2.5.
            foreach (array_chunk($changed, 500) as $batch) {
                $tickets = array_values(array_unique(array_column($batch, 'N_TICKET')));
                $ids = array_map('hex2bin', array_column($batch, 'ROW_ID'));
                $ticketParams = implode(',', array_fill(0, count($tickets), '?'));
                $idParams = implode(',', array_fill(0, count($ids), '?'));
                $details = $connection->select('SELECT '.self::DETAIL_FIELDS
                    ." FROM sale s WHERE s.n_ticket IN ($ticketParams) AND s.RDB\$DB_KEY IN ($idParams)",
                    [...$tickets, ...$ids]);
                array_push($rows, ...$details);
            }
            if (count($rows) !== count($changed)) {
                throw new RuntimeException('Не удалось прочитать все выбранные продажи.');
            }

            return self::pageResult($rows, $unchanged, end($manifest));
        });
    }

    private static function pageResult(array $rows, array $unchanged, array|false $last): array
    {
        return ['rows' => $rows, 'unchanged' => $unchanged,
            'last_key' => $last ? array_intersect_key($last, array_flip(self::KEY)) : null];
    }

    public static function query(string $day, ?array $cursor, int $limit, bool $full = false): array
    {
        $where = self::cursorWhere($cursor, self::KEY);
        $limit = max(1, min(3000, $limit));
        $order = implode(', ', array_map(fn ($key) => 's.'.$key, self::KEY));
        $columns = implode(', ', array_map(fn ($key) => 's.'.$key, [...self::KEY, ...self::REPORT_FIELDS]));
        $projection = "s.RDB\$DB_KEY AS ROW_ID, $columns";
        // Возврат может пройти в другой кассе и относиться к старой продаже.
        // UNION убирает повтор исходной продажи при нескольких строках возврата.
        $source = "WITH candidates AS (
                    SELECT $projection FROM sale s WHERE s.datesale = ? AND s.awtcodsale IN (9984, 9999, 9900)
                    UNION
                    SELECT $projection FROM sale r JOIN sale s
                        ON s.n_ticket = r.n_ticket AND s.dob_n_ticket = r.dob_n_ticket
                    WHERE r.datesale = ? AND r.type_oper IN (1,4,5,6) AND r.kkm_ok = 1 AND r.comment_tick <> 8
                        AND s.type_oper = 0 AND s.awtcodsale IN (9984, 9999, 9900)
                        AND ((s.datestart = DATE '1899-12-30' AND r.datestart = DATE '1899-12-30')
                            OR (s.datestart <> DATE '1899-12-30' AND r.datestart <> DATE '1899-12-30'))
                )";
        $range = "FROM candidates s WHERE 1 = 1$where ORDER BY $order";
        if ($full) {
            $sql = "$source, page_keys AS (SELECT FIRST $limit s.ROW_ID $range) SELECT ".self::DETAIL_FIELDS
                ." FROM page_keys p JOIN sale s ON s.RDB\$DB_KEY = p.ROW_ID ORDER BY $order";
        } else {
            $sql = "$source SELECT FIRST $limit s.*, CAST(s.TIMESALE AS VARCHAR(20)) AS TIMESALE $range";
        }

        return [$sql, [$day, $day]];
    }

    public function legacyPage(?array $cursor): array
    {
        $where = self::cursorWhere($cursor, self::KEY);
        $limit = (int) config('station_sales.legacy_page_size');
        $order = implode(', ', array_map(fn ($key) => 's.'.$key, self::KEY));
        $connection = new StationConnection(config('station_sales.legacy'), 5);

        return $connection->select("SELECT FIRST $limit s.*, CAST(s.TIMESALE AS VARCHAR(20)) AS TIMESALE FROM SALE_ALL s WHERE 1 = 1$where ORDER BY $order");
    }

    private static function cursorWhere(?array $cursor, array $columns): string
    {
        if ($cursor !== null) {
            // Keyset по ПОЛНОМУ PK SALE, без SKIP и неоднозначного порядка.
            // TIMESALE инлайнится: pdo_firebird теряет доли секунды при bind.
            $parts = [];
            $equal = [];
            foreach ($columns as $column) {
                $value = $cursor[$column] ?? throw new InvalidArgumentException('Неполный курсор SALE.');
                if ($column === 'TIMESALE') {
                    if (! preg_match('/^\d{2}:\d{2}:\d{2}(?:\.\d{1,4})?$/D', (string) $value)) {
                        throw new InvalidArgumentException('Неверное время в курсоре SALE.');
                    }
                    $literal = "TIME '$value'";
                } else {
                    $literal = "'".str_replace("'", "''", (string) $value)."'";
                }
                $parts[] = '('.implode(' AND ', [...$equal, "s.$column > $literal"]).')';
                $equal[] = "s.$column = $literal";
            }

            return ' AND ('.implode(' OR ', $parts).')';
        }

        return '';
    }

    private static function recordKey(array $row): string
    {
        $key = [];
        foreach (self::KEY as $column) {
            if (! isset($row[$column])) {
                throw new InvalidArgumentException('В строке SALE отсутствует '.$column);
            }
            $key[$column] = (string) $row[$column];
        }
        // 12:00:00 и 12:00:00.0000 — один и тот же первичный ключ.
        if (str_contains($key['TIMESALE'], '.')) {
            $key['TIMESALE'] = rtrim(rtrim($key['TIMESALE'], '0'), '.');
        }
        return hash('sha256', json_encode($key, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }

    private static function reportHash(array $row): string
    {
        $values = array_map(fn ($field) => isset($row[$field]) ? (string) $row[$field] : null, self::REPORT_FIELDS);

        return hash('sha256', json_encode($values, JSON_THROW_ON_ERROR));
    }

    public static function record(array $row, int $stationCode, string $syncedAt): array
    {
        $record = [
            'record_key' => self::recordKey($row), 'report_hash' => self::reportHash($row),
            'source_station' => $stationCode,
        ];
        foreach (['AWTCOD', 'AWTCODSALE', 'DATESALE', 'DATESTART', 'TYPE_OPER', 'KKM_OK', 'TICK_BACK',
            'PRICE_PLACE', 'PRICE_BAG', 'PRICE_PL_ISX', 'PRICE_BAG_ISX'] as $column) {
            $record[strtolower($column)] = $row[$column] ?? null;
        }
        $record['raw_data'] = json_encode($row, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        $record['synced_at'] = $syncedAt;

        return $record;
    }
}
