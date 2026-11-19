<?php

namespace App\Services;

use InvalidArgumentException;

/** Только чтение SALE; ни процедур продажи, ни изменений в базе станции. */
class StationSalesSource
{
    public const KEY = ['DATESALE', 'TIMESALE', 'AWTCODSALE', 'WRKCOD', 'TYPE_OPER', 'N_TICKET', 'DOB_N_TICKET', 'DATESTART'];

    public function __construct(private readonly StationRegistry $stations, private readonly SiteSettings $settings) {}

    private const DETAIL_FIELDS = "s.*, CAST(s.TIMESALE AS VARCHAR(20)) AS TIMESALE,
        (SELECT n.napname FROM naplst n WHERE n.napcod = s.napcod) AS NAPNAME,
        (SELECT FIRST 1 t.statname FROM traces t WHERE t.napcod = s.napcod AND t.statcod = s.nomstat) AS STATNAME";

    public static function extraDays(object $run): array
    {
        $dates = array_keys(json_decode($run->refund_days ?? '[]', true, flags: JSON_THROW_ON_ERROR));

        return array_values(array_filter($dates, fn ($day) => $day < $run->date_from || $day > $run->date_to));
    }

    public function rows(int $stationCode, array $chunk): \Generator
    {
        $station = $this->stations->all()[$stationCode] ?? null;
        if (! $station) {
            throw new InvalidArgumentException('Станция отключена или не настроено подключение.');
        }
        [$sql, $bindings] = self::query($chunk);
        $connection = new StationConnection($station, min(5, $this->settings->stationConnectTimeout()));

        yield from $connection->rows($sql, $bindings);
    }

    public static function query(array $chunk): array
    {
        if ($chunk['extra'] === []) {
            $condition = 'DATESALE BETWEEN ? AND ?';
            $dates = [$chunk['from'], $chunk['to']];
        } else {
            $condition = 'DATESALE IN ('.implode(',', array_fill(0, count($chunk['extra']), '?')).')';
            $dates = $chunk['extra'];
        }

        // Включаем исходные продажи по возвратам, даже если продажа была
        // за границами порции. UNION убирает дубликаты по физическому ключу.
        $sql = "WITH candidates AS (
                    SELECT s.RDB\$DB_KEY AS ROW_ID FROM sale s
                    WHERE s.$condition AND s.awtcodsale IN (9984, 9999, 9900)
                    UNION
                    SELECT s.RDB\$DB_KEY FROM sale r JOIN sale s
                        ON s.n_ticket = r.n_ticket AND s.dob_n_ticket = r.dob_n_ticket
                    WHERE r.$condition AND r.type_oper IN (1,4,5,6) AND r.kkm_ok = 1 AND r.comment_tick <> 8
                        AND s.type_oper = 0 AND s.awtcodsale IN (9984, 9999, 9900)
                        AND ((s.datestart = DATE '1899-12-30' AND r.datestart = DATE '1899-12-30')
                            OR (s.datestart <> DATE '1899-12-30' AND r.datestart <> DATE '1899-12-30'))
                ) SELECT ".self::DETAIL_FIELDS." FROM candidates p JOIN sale s ON s.RDB\$DB_KEY = p.ROW_ID";

        return [$sql, [...$dates, ...$dates]];
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

    public static function record(array $row, int $stationCode, string $syncedAt): array
    {
        $record = [
            'record_key' => self::recordKey($row),
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
