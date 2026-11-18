<?php

namespace App\Services;

use InvalidArgumentException;

/** Только чтение SALE; ни процедур продажи, ни изменений в базе станции. */
class StationSalesSource
{
    public const KEY = ['DATESALE', 'TIMESALE', 'AWTCODSALE', 'WRKCOD', 'TYPE_OPER', 'N_TICKET', 'DOB_N_TICKET', 'DATESTART'];

    public function __construct(private readonly StationRegistry $stations, private readonly SiteSettings $settings) {}

    public function page(int $stationCode, string $day, ?array $cursor): array
    {
        $station = $this->stations->all()[$stationCode] ?? null;
        if (! $station) {
            throw new InvalidArgumentException('Станция отключена или не настроено подключение.');
        }

        [$sql, $bindings] = self::query($day, $cursor, (int) config('station_sales.page_size'));
        $connection = new StationConnection($station, min(5, $this->settings->stationConnectTimeout()));

        return $connection->select($sql, $bindings);
    }

    public static function query(string $day, ?array $cursor, int $limit): array
    {
        $where = self::cursorWhere($cursor, array_slice(self::KEY, 1));
        $limit = max(1, min(1000, $limit));
        $order = implode(', ', array_map(fn ($key) => 's.'.$key, self::KEY));

        // Справочники нужны и для полной копии прежнего SALE_ALL.
        // PDO обрезает доли TIME и при ЧТЕНИИ. Второй alias заменяет значение
        // из s.* строковым CAST, иначе keyset зациклится на дробной секунде.
        return ["SELECT FIRST $limit s.*, CAST(s.TIMESALE AS VARCHAR(20)) AS TIMESALE,
                    (SELECT n.napname FROM naplst n WHERE n.napcod = s.napcod) AS NAPNAME,
                    (SELECT FIRST 1 t.statname FROM traces t WHERE t.napcod = s.napcod AND t.statcod = s.nomstat) AS STATNAME
                 FROM sale s WHERE s.datesale = ? AND s.awtcodsale IN (9984, 9999, 9900)$where
                 ORDER BY $order", [$day]];
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

    public static function record(array $row, int $stationCode, string $syncedAt): array
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
        $record = [
            'record_key' => hash('sha256', json_encode($key, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)),
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
