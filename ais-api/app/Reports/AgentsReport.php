<?php

namespace App\Reports;

use App\Services\StationConnection;
use App\Services\StationRegistry;

/**
 * Отчёт «Агентские билеты» — перенос legacy apiv2/reports/agents.php
 * (ядро — из ais/agents.php). Читает базы продаж станций (Firebird).
 *
 * Секции: продажи (TYPE_OPER=0), возвраты (TYPE_OPER 1/5), возвраты
 * кассирами. Дата продажи в возвратах определяется по паре
 * N_TICKET+DOB_N_TICKET из строки продажи — запрос порциями по 1000
 * номеров (страховка от лимита IN).
 */
class AgentsReport implements Report
{
    /** Агентские продажи: код кассы > 5799, кроме сайта (9984). */
    private const AGENT_CONDITION = 'sale.awtcodsale>5799 and sale.awtcodsale<>9984';

    private const COLS_SALES = [
        ['key' => 'i', 'title' => '№', 'type' => 'number'],
        ['key' => 'AV', 'title' => 'Автостанция', 'type' => 'string'],
        ['key' => 'DATESALE', 'title' => 'Дата продажи', 'type' => 'date'],
        ['key' => 'DATESALE_T', 'title' => 'Время продажи', 'type' => 'time'],
        ['key' => 'N_TICKET', 'title' => 'Номер билета', 'type' => 'string'],
        ['key' => 'ATPNAME', 'title' => 'АТП', 'type' => 'string'],
        ['key' => 'raice', 'title' => 'Рейс', 'type' => 'string'],
        ['key' => 'STATNAME', 'title' => 'Станция', 'type' => 'string'],
        ['key' => 'datestart', 'title' => 'Дата отправл.', 'type' => 'date'],
        ['key' => 'timestart', 'title' => 'Время отправл.', 'type' => 'time'],
        ['key' => 'N_PLACE', 'title' => 'место', 'type' => 'number'],
        ['key' => 'PRICE_PLACE', 'title' => 'Стоимость проезда', 'type' => 'money'],
        ['key' => 'PRICE_BAG', 'title' => 'Багаж', 'type' => 'money'],
        ['key' => 'STRWANT', 'title' => 'Страховка', 'type' => 'money'],
        ['key' => 'vich', 'title' => 'Вычерк', 'type' => 'string'],
        ['key' => 'back', 'title' => 'Возврат', 'type' => 'string'],
        ['key' => 'inved', 'title' => 'Уехал', 'type' => 'string'],
    ];

    private const COLS_RETURNS = [
        ['key' => 'i', 'title' => '№', 'type' => 'number'],
        ['key' => 'AV', 'title' => 'Автостанция', 'type' => 'string'],
        ['key' => 'N_TICKET', 'title' => 'Номер билета', 'type' => 'string'],
        ['key' => 'ATPNAME', 'title' => 'АТП', 'type' => 'string'],
        ['key' => 'raice', 'title' => 'Рейс', 'type' => 'string'],
        ['key' => 'STATNAME', 'title' => 'Станция', 'type' => 'string'],
        ['key' => 'datestart', 'title' => 'Дата отправл.', 'type' => 'date'],
        ['key' => 'timestart', 'title' => 'Время отправл.', 'type' => 'time'],
        ['key' => 'N_PLACE', 'title' => 'место', 'type' => 'number'],
        ['key' => 'PRICE_PL_ISX', 'title' => 'Стоимость проезда', 'type' => 'money'],
        ['key' => 'PRICE_PLACE', 'title' => 'Сумма возврата проезд', 'type' => 'money'],
        ['key' => 'PRICE_BAG', 'title' => 'Багаж', 'type' => 'money'],
        ['key' => 'STRWANT', 'title' => 'Страховка', 'type' => 'money'],
        ['key' => 'DATE_PROD', 'title' => 'Дата продажи', 'type' => 'date'],
        ['key' => 'DATE_PROD_T', 'title' => 'Время продажи', 'type' => 'time'],
        ['key' => 'DATESALE', 'title' => 'Дата возврата', 'type' => 'date'],
        ['key' => 'DATESALE_T', 'title' => 'Время возврата', 'type' => 'time'],
    ];

    public function __construct(private readonly StationRegistry $stations) {}

    public function meta(): array
    {
        return [
            'title' => 'Агентские билеты',
            'group' => 'Продажи/возвраты',
            'filters' => [
                ['name' => 'stan', 'type' => 'select', 'label' => 'Хозяйство',
                    'options' => $this->stations->selectOptions(),
                    // Первая развёрнутая станция (в legacy был захардкожен Орёл 5701).
                    'default' => (string) array_key_first($this->stations->all())],
                ['name' => 'date1', 'type' => 'date', 'label' => 'Дата поездки, с', 'default' => ''],
                ['name' => 'date2', 'type' => 'date', 'label' => 'Дата поездки, по', 'default' => ''],
                ['name' => 'date3', 'type' => 'date', 'label' => 'Дата продажи, с',
                    'default' => date('Y-m-01', strtotime('first day of last month'))],
                ['name' => 'date4', 'type' => 'date', 'label' => 'Дата продажи, по',
                    'default' => date('Y-m-t', strtotime('first day of last month'))],
            ],
        ];
    }

    public function run(array $filters): array
    {
        $stan = trim((string) ($filters['stan'] ?? ''));
        // Пустая дата = граница не задана (широкий диапазон).
        $date1 = ($filters['date1'] ?? '') ?: '1900-01-01';
        $date2 = ($filters['date2'] ?? '') ?: '2999-12-31';
        $date3 = ($filters['date3'] ?? '') ?: '1900-01-01';
        $date4 = ($filters['date4'] ?? '') ?: '2999-12-31';

        $errors = '';
        $rowsSales = $rowsRet = $rowsCash = [];
        $tSales = ['PRICE_PLACE' => 0, 'PRICE_BAG' => 0, 'STRWANT' => 0];
        $tRet = ['PRICE_PL_ISX' => 0, 'PRICE_PLACE' => 0, 'PRICE_BAG' => 0, 'STRWANT' => 0];
        $tCash = ['PRICE_PL_ISX' => 0, 'PRICE_PLACE' => 0, 'PRICE_BAG' => 0, 'STRWANT' => 0];
        $inved = 0.0;   // «уехало»
        $inved2211 = 0.0; // «уехало + вычеркнуто»

        if ($stan === '') {
            return $this->result($rowsSales, $rowsRet, $rowsCash, $tSales, $tRet, $tCash,
                $inved, $inved2211, 0, 'Не выбрано хозяйство', $filters);
        }

        foreach ($this->stations->subset($stan) as $awtcod => $station) {
            try {
                $db = new StationConnection($station);
            } catch (\Throwable) {
                $errors .= 'Нет соединения с базой '.$station['name'].'<br>';

                continue;
            }

            $sales = $this->query($db, $this->salesSql(), [$date1, $date2, $date3, $date4], $station['name'], $errors);
            $returns = $this->query($db, $this->returnsSql(), [$date1, $date2, $date3, $date4], $station['name'], $errors);
            $cashierReturns = $this->query($db, $this->cashierReturnsSql(), [$awtcod, $awtcod, $date3, $date4], $station['name'], $errors);

            // Даты продажи для возвратов: по номерам билетов порциями.
            $prodMap = $this->saleDates($db, array_merge($returns, $cashierReturns), $station['name'], $errors);

            $av = $station['name'];

            foreach ($sales as $v) {
                $strwant = $v['STRWANT_BACK'] ? 0 : (float) $v['STRWANT'];
                $ts = strtotime($v['DATESALE'].' '.$v['TIMESALE']);
                $inv = $v['IS_PRN'] == 1 && ! $v['MAY_BACK'] && ! $v['TICK_BACK'];
                if ($inv) {
                    $inved += (float) $v['PRICE_PLACE'] + (float) $v['PRICE_BAG'];
                }
                if ($v['IS_PRN'] == 1 && ! $v['TICK_BACK']) {
                    $inved2211 += (float) $v['PRICE_PLACE'] + (float) $v['PRICE_BAG'];
                }
                $rowsSales[] = [
                    'i' => count($rowsSales) + 1, 'AV' => $av,
                    'DATESALE' => date('d.m.Y', $ts), 'DATESALE_T' => date('H:i:s', $ts),
                    'N_TICKET' => $v['N_TICKET'],
                    'ATPNAME' => $v['ATPNAME'],
                    'raice' => $v['RAICENAME'].'-'.$v['NAPNAME'],
                    'STATNAME' => $v['STATNAME'],
                    'datestart' => date('d.m.Y', strtotime($v['DATESTART'])),
                    'timestart' => date('H:i', strtotime((string) $v['TIMESTART'])),
                    'N_PLACE' => $v['N_PLACE'],
                    'PRICE_PLACE' => (float) $v['PRICE_PLACE'],
                    'PRICE_BAG' => (float) $v['PRICE_BAG'],
                    'STRWANT' => $strwant,
                    'vich' => $v['MAY_BACK'] ? 'Да' : '',
                    'back' => $v['TICK_BACK'] ? 'Да' : '',
                    'inved' => $inv ? 'Да' : '',
                ];
                $tSales['PRICE_PLACE'] += (float) $v['PRICE_PLACE'];
                $tSales['PRICE_BAG'] += (float) $v['PRICE_BAG'];
                $tSales['STRWANT'] += $strwant;
            }

            foreach ($returns as $v) {
                $rowsRet[] = $this->returnRow($v, $av, count($rowsRet) + 1, $prodMap, $tRet, $inved, $inved2211, false);
            }

            foreach ($cashierReturns as $v) {
                $rowsCash[] = $this->returnRow($v, $av, count($rowsCash) + 1, $prodMap, $tCash, $inved, $inved2211, true);
            }
        }

        $dolg = ($tSales['PRICE_PLACE'] + $tSales['PRICE_BAG'] + $tSales['STRWANT'])
            - ($tRet['PRICE_PLACE'] + $tRet['PRICE_BAG'] + $tRet['STRWANT']);

        return $this->result($rowsSales, $rowsRet, $rowsCash, $tSales, $tRet, $tCash,
            $inved, $inved2211, $dolg, $errors, $filters);
    }

    /** Строка секции возвратов/возвратов кассирами (структуры совпадают). */
    private function returnRow(
        array $v,
        string $av,
        int $i,
        array $prodMap,
        array &$totals,
        float &$inved,
        float &$inved2211,
        bool $cashier,
    ): array {
        // STRWANT_BACK/IS_PRN есть не во всех запросах (как в legacy) — ?? null
        // сохраняет legacy-семантику отсутствующих колонок.
        $strwant = ($v['STRWANT_BACK'] ?? null) ? 0 : (float) $v['STRWANT'];
        $tsReturn = strtotime($v['DATESALE'].' '.$v['TIMESALE']);
        $tsProd = $prodMap[$v['N_TICKET'].'|'.$v['DOB_N_TICKET']] ?? null;

        if (($v['IS_PRN'] ?? null) == 1 && ! $v['MAY_BACK'] && ! $v['TICK_BACK']) {
            $inved += (float) $v['PRICE_PLACE'] + (float) $v['PRICE_BAG'];
        }
        if ($cashier && ($v['IS_PRN'] ?? null) == 1 && ! $v['TICK_BACK']) {
            $inved2211 += (float) $v['PRICE_PLACE'] + (float) $v['PRICE_BAG'];
        }

        $totals['PRICE_PL_ISX'] += (float) $v['PRICE_PL_ISX'];
        $totals['PRICE_PLACE'] += (float) $v['PRICE_PLACE'];
        $totals['PRICE_BAG'] += (float) $v['PRICE_BAG'];
        $totals['STRWANT'] += $strwant;

        // Рейс: в legacy у возвратов кассирами имена клеятся без дефиса.
        $raice = $cashier ? $v['RAICENAME'].$v['NAPNAME'] : $v['RAICENAME'].'-'.$v['NAPNAME'];

        return [
            'i' => $i, 'AV' => $av, 'N_TICKET' => $v['N_TICKET'],
            'ATPNAME' => $v['ATPNAME'],
            'raice' => $raice,
            'STATNAME' => $v['STATNAME'],
            'datestart' => date('d.m.Y', strtotime($v['DATESTART'])),
            'timestart' => date('H:i', strtotime((string) $v['TIMESTART'])),
            'N_PLACE' => $v['N_PLACE'],
            'PRICE_PL_ISX' => (float) $v['PRICE_PL_ISX'],
            'PRICE_PLACE' => (float) $v['PRICE_PLACE'],
            'PRICE_BAG' => (float) $v['PRICE_BAG'],
            'STRWANT' => $strwant,
            'DATE_PROD' => $tsProd ? date('d.m.Y', strtotime($tsProd)) : '',
            'DATE_PROD_T' => $tsProd ? date('H:i:s', strtotime($tsProd)) : '',
            'DATESALE' => date('d.m.Y', $tsReturn),
            'DATESALE_T' => date('H:i:s', $tsReturn),
        ];
    }

    private function query(StationConnection $db, string $sql, array $bindings, string $stationName, string &$errors): array
    {
        try {
            return $db->select($sql, $bindings);
        } catch (\Throwable) {
            $errors .= 'Ошибка работы с базой '.$stationName.'<br>';

            return [];
        }
    }

    /** Даты продажи по номерам билетов возвратов: N_TICKET|DOB_N_TICKET → «дата время». */
    private function saleDates(StationConnection $db, array $returnRows, string $stationName, string &$errors): array
    {
        $numbers = [];
        foreach ($returnRows as $row) {
            if ($row['N_TICKET'] !== null && $row['N_TICKET'] !== '') {
                $numbers[$row['N_TICKET']] = true;
            }
        }

        $map = [];
        foreach (array_chunk(array_keys($numbers), 1000) as $chunk) {
            $placeholders = implode(',', array_fill(0, count($chunk), '?'));
            try {
                $rows = $db->select(
                    "SELECT N_TICKET, DOB_N_TICKET, datesale, timesale FROM SALE WHERE TYPE_OPER=0 AND N_TICKET IN ($placeholders)",
                    $chunk,
                );
            } catch (\Throwable) {
                $errors .= 'Ошибка получения дат продажи '.$stationName.'<br>';

                continue;
            }
            foreach ($rows as $r) {
                $map[$r['N_TICKET'].'|'.$r['DOB_N_TICKET']] = $r['DATESALE'].' '.$r['TIMESALE'];
            }
        }

        return $map;
    }

    private function result(
        array $rowsSales,
        array $rowsRet,
        array $rowsCash,
        array $tSales,
        array $tRet,
        array $tCash,
        float $inved,
        float $inved2211,
        float $dolg,
        string $errors,
        array $filters,
    ): array {
        return [
            'filters' => $filters,
            'errors' => $errors,
            'summary' => [
                ['label' => 'Уехало, руб.', 'value' => round($inved, 2)],
                ['label' => 'Уехало + вычеркнуто, руб.', 'value' => round($inved2211, 2)],
                ['label' => 'Агент должен заплатить (продажи−возвраты), руб.', 'value' => round($dolg, 2)],
            ],
            'sections' => [
                ['id' => 'sales', 'title' => 'Продажи', 'collapsed' => true,
                    'columns' => self::COLS_SALES, 'rows' => $rowsSales,
                    'totals' => ['AV' => 'ИТОГО:',
                        'PRICE_PLACE' => round($tSales['PRICE_PLACE'], 2),
                        'PRICE_BAG' => round($tSales['PRICE_BAG'], 2),
                        'STRWANT' => round($tSales['STRWANT'], 2)]],
                ['id' => 'returns', 'title' => 'Возвраты', 'collapsed' => true,
                    'columns' => self::COLS_RETURNS, 'rows' => $rowsRet,
                    'totals' => ['N_TICKET' => 'ИТОГО:',
                        'PRICE_PL_ISX' => round($tRet['PRICE_PL_ISX'], 2),
                        'PRICE_PLACE' => round($tRet['PRICE_PLACE'], 2),
                        'PRICE_BAG' => round($tRet['PRICE_BAG'], 2),
                        'STRWANT' => round($tRet['STRWANT'], 2)]],
                ['id' => 'cashier_returns', 'title' => 'Возвраты кассирами', 'collapsed' => true,
                    'columns' => self::COLS_RETURNS, 'rows' => $rowsCash,
                    'totals' => ['N_TICKET' => 'ИТОГО:',
                        'PRICE_PL_ISX' => round($tCash['PRICE_PL_ISX'], 2),
                        'PRICE_PLACE' => round($tCash['PRICE_PLACE'], 2),
                        'PRICE_BAG' => round($tCash['PRICE_BAG'], 2),
                        'STRWANT' => round($tCash['STRWANT'], 2)]],
            ],
        ];
    }

    private function salesSql(): string
    {
        $agent = self::AGENT_CONDITION;

        return "SELECT sale.datesale,sale.timesale,SALE.IS_PRN,SALE.ATPCOD,SALE.N_TICKET,SALE.n_PLACE,SALE.STRWANT_BACK,
                ATPLSTALL.ATPNAME,EVRYRAICE.RAICENAME,SALE.MAY_BACK,SALE.TICK_BACK,NAPLST.NAPNAME,EVRYRAICE.TIMESTART,
                SALE.DATESTART,SALE.STRWANT,SALE.PRICE_PLACE,SALE.PRICE_BAG,TRACES.STATNAME
            FROM SALE
            LEFT JOIN TRACES ON (TRACES.AWTCOD=SALE.AWTCOD AND TRACES.NAPCOD=SALE.NAPCOD AND TRACES.STATCOD=SALE.NOMSTAT)
            LEFT JOIN EVRYRAICE ON (EVRYRAICE.AWTCOD=SALE.AWTCOD AND EVRYRAICE.DATESTART=SALE.DATESTART AND EVRYRAICE.NAPCOD=SALE.NAPCOD AND EVRYRAICE.RAICECOD=SALE.RAICECOD)
            LEFT JOIN NAPLST ON (SALE.NAPCOD=NAPLST.NAPCOD AND SALE.AWTCOD=NAPLST.AWTCOD)
            LEFT JOIN ATPLSTALL ON (SALE.ATPCOD=ATPLSTALL.ATPCOD)
            WHERE SALE.TYPE_OPER=0 AND $agent
              AND SALE.DATESTART BETWEEN ? AND ? AND SALE.DATEsale BETWEEN ? AND ?
            ORDER BY SALE.DATEsale, sale.TIMEsale";
    }

    private function returnsSql(): string
    {
        $agent = self::AGENT_CONDITION;

        // Набор колонок повторяет legacy 1:1 (в т.ч. БЕЗ IS_PRN — из-за этого
        // возвраты в legacy никогда не попадали в «уехало»; сохраняем поведение).
        return "SELECT sale.dob_n_ticket,sale.price_pl_isx,sale.datesale as DATESALE,sale.timesale,sale.awtcodsale,
                SALE.ATPCOD,SALE.N_TICKET,SALE.n_PLACE,SALE.STRWANT_BACK,ATPLSTALL.ATPNAME,EVRYRAICE.RAICENAME,
                SALE.MAY_BACK,SALE.TICK_BACK,NAPLST.NAPNAME,EVRYRAICE.TIMESTART,SALE.DATESTART,
                SALE.STRWANT,SALE.PRICE_PLACE,SALE.PRICE_BAG,TRACES.STATNAME
            FROM SALE
            LEFT JOIN TRACES ON (TRACES.AWTCOD=SALE.AWTCOD AND TRACES.NAPCOD=SALE.NAPCOD AND TRACES.STATCOD=SALE.NOMSTAT)
            LEFT JOIN EVRYRAICE ON (EVRYRAICE.AWTCOD=SALE.AWTCOD AND EVRYRAICE.DATESTART=SALE.DATESTART AND EVRYRAICE.NAPCOD=SALE.NAPCOD AND EVRYRAICE.RAICECOD=SALE.RAICECOD)
            LEFT JOIN NAPLST ON (SALE.NAPCOD=NAPLST.NAPCOD AND SALE.AWTCOD=NAPLST.AWTCOD)
            LEFT JOIN ATPLSTALL ON (SALE.ATPCOD=ATPLSTALL.ATPCOD)
            WHERE (SALE.TYPE_OPER=5 or SALE.TYPE_OPER=1) AND $agent
              AND SALE.DATESTART BETWEEN ? AND ? AND SALE.DATEsale BETWEEN ? AND ?
            ORDER BY SALE.DATEsale, sale.TIMEsale";
    }

    private function cashierReturnsSql(): string
    {
        return "SELECT s.DATESALE, s.TIMESALE, s.AWTCODSALE,
                case substring(s.dob_n_ticket from 9 for 12) when '    ' then '0' else substring(s.dob_n_ticket from 9 for 12) end as awtcods,
                coalesce(awtlst.awtname,'Нет данных') as awtnames,
                s.WRKCOD, s.TYPE_OPER, s.N_TICKET, s.DOB_N_TICKET, s.DATESTART, s.AWTCOD, s.NAPCOD, s.RAICECOD, s.N_PLACE,
                s.KATPAS, s.IS_PRN, s.START_NOMSTAT, s.NOMSTAT, s.PRICE_PLACE, s.PRICE_BAG, s.COM_SB, s.COM_SB_DOP, s.STRALL,
                s.STRWANT, s.PRICE_USL, (s.ZAKAZ + s.ZAKAZ_INT + s.BRON) as ZAKAZ, s.PRICE_PL_ISX, s.PRICE_BAG_ISX,
                s.GO_DATESTART, s.GO_NAPCOD, s.GO_RAICECOD, s.GO_ATPCOD, s.PROC_BACK, s.TYPE_TICK, s.KKM_OK, s.COMMENT_TICK,
                s.MAY_BACK, s.TICK_BACK, s.INTERNET_SALE, TRACES.STATNAME, NAPLST.NAPNAME, WRKLST.WRKNAME, EVRYRAICE.TIMESTART,
                atplstall.atpname, s.N_Zakaz_Tour
            FROM SALE s
            left join atplstall on atplstall.atpcod = s.GO_ATPCOD
            left join WRKLST on WRKLST.AWTCOD = s.AWTCODSALE and WRKLST.WRKCOD = s.WRKCOD and WRKLST.DEPARTCOD = s.DEPARTCOD
            left join EVRYRAICE on EVRYRAICE.AWTCOD = s.AWTCOD and EVRYRAICE.NAPCOD = s.NAPCOD and EVRYRAICE.RAICECOD = s.RAICECOD and EVRYRAICE.DATESTART = s.DATESTART
            left join TRACES on TRACES.AWTCOD = s.AWTCOD and TRACES.NAPCOD = s.NAPCOD and TRACES.STATCOD = s.NOMSTAT
            left join NAPLST on NAPLST.AWTCOD = s.AWTCOD and NAPLST.NAPCOD = s.NAPCOD
            left join awtlst on awtlst.awtcod = case substring(s.dob_n_ticket from 9 for 12) when '    ' then '0' else substring(s.dob_n_ticket from 9 for 12) end
            WHERE s.tick_back <> 0 and s.type_oper <> 0
              and substring(s.dob_n_ticket from 9 for 12) <> ?
              and substring(s.dob_n_ticket from 9 for 4) <> substring(s.dob_n_ticket from 5 for 4)
              and s.awtcodsale = ? and s.kkm_ok <> 0 and (s.datesale between ? and ?)
            ORDER BY s.TYPE_TICK, substring(s.dob_n_ticket from 9 for 12), s.DATESALE";
    }
}
