<?php

namespace App\Services\Buster;

use App\Exceptions\BusterException;

/**
 * Операции над базой продаж станции (BUSTER). Перенос действий legacy
 * ais/api/api2.php: download_list_of_routes, free_places, start_buy,
 * end_buy, tickets_back, raice_status, download_list_of_stations.
 *
 * Вся бизнес-логика продажи — в вендорных хранимых процедурах KS_*;
 * этот класс лишь корректно их вызывает (порядок параметров и '@'-упаковка
 * повторяют боевой api2.php 1:1 — менять осознанно).
 */
class BusterGateway
{
    public function __construct(
        private readonly BusterConnection $db,
        private readonly int $agentCode,          // AGENT_CODE в BUSTER (legacy 9984)
        private readonly int $workerCode = 990,   // AGENT_WRK продажи мест
        private readonly int $baggageWorkerCode = 991,
    ) {}

    /** Пункты, до которых продаются билеты со станции (KS_STATIONS). */
    public function destinations(int $awtcod): array
    {
        return $this->db->select(
            'SELECT STAT_NAME AS STATION_NAME, PRE_SALE_DAYS AS PRE_SALES_DAY FROM KS_STATIONS (0, ?, 0, ?)',
            [$awtcod, $awtcod],
        );
    }

    /** Рейсы на дату до станции назначения (по имени станции в TRACES). */
    public function routes(int $awtcod, string $stationName, string $date, bool $forSale = true): array
    {
        $extraWhere = '';
        if ($forSale) {
            $extraWhere = ' and available=1 and evryraice.transit=0';
            if ($date === date('Y-m-d')) {
                // Не показываем рейсы, до отправления которых < ~33 минут.
                $extraWhere .= " and timestart>'".date('H:i:s', time() + 2000)."'";
            }
        }

        $fields = <<<'SQL'
                buslst.busname,
                evryraice.raicename,
                evryraice.platnom,
                evryraice.nomkat,
                naplst.napname,
                evryraice.datestart,
                evryraice.timestart,
                %TIME_TO_END% time_to_end,
                TARIFS2D.paysoftall,
                TARIFS2D.payhardall,
                TARIFS2D.paysoftchd,
                TARIFS2D.payhardchd,
                TARIFS2D.paysoftbag,
                TARIFS2D.payhardbag,
                TARIFS2D.DOBRSTR,
                evryraice.available,
                evryraice.canceled,
                evryraice.disrupt,
                evryraice.gone,
                evryraice.transit,
                (evryraice.setplbus - evryraice.setpl - evryraice.setpl_otl) Col_Free_Pl,
                traces.kilom,
                evryraice.napcod,
                evryraice.raicecod,
                TARIFS2D.statcode,
                atplstall.ATPOFFICIAL as atpname,
                evryraice.setplbus,
                TARIFS2D.katcode,
                evryraice.sale_bound_before,
                evryraice.sale_bound_after,
                evryraice.internet_sale_available,
                evryraice.RAICE_CATEGORY AS FRAHT,
                (evryraice.martypcod * naplst.reg_pasp_data) reg_pas_data
SQL;

        $fieldsWithArrival = str_replace('%TIME_TO_END%', '(evryraice.timestart + tracetime.time_to_stat*60)', $fields);
        $fieldsNoArrival = str_replace('%TIME_TO_END%', 'evryraice.timestart', $fields);

        $sql = "
            select {$fieldsWithArrival},
                (tracetime.time_to_stat+1) time_kind
                from evryraice
                inner join buslst on (evryraice.buscod = buslst.buscod)
                inner join atplstall on (evryraice.atpcod = atplstall.atpcod)
                inner join naplst on (evryraice.awtcod = naplst.awtcod) and (evryraice.napcod = naplst.napcod)
                inner join TARIFS2D on (evryraice.nomkat = TARIFS2D.katcode) and (evryraice.napcod = TARIFS2D.napcode)
                    and (evryraice.awtcod = TARIFS2D.awtcode)
                inner join traces on (TARIFS2D.awtcode = traces.awtcod) and (TARIFS2D.napcode = traces.napcod)
                    and (TARIFS2D.statcode = traces.statcod)
                left outer join tracetime on (traces.awtcod = tracetime.awtcod) and (traces.napcod = tracetime.napcod)
                    and (traces.statcod = tracetime.statcod)
            where
                ((traces.statname = ?)
                and (traces.isdel = 0) and (TARIFS2D.paysoftall>0)
                and (evryraice.datestart = ?){$extraWhere}
                and (evryraice.awtcod = ?)
                and (evryraice.napcod = traces.napcod) and (evryraice.awtcod = traces.awtcod)
                and (not (evryraice.tracetimecod is null) and (evryraice.tracetimecod = tracetime.traicetimecod))
                and (TARIFS2D.prim = 1)
                and RAICETYPCOD<>3
                and not((evryraice.canceled = 5))
                and TARIFS2D.startstatcode=0)
            union
            select {$fieldsNoArrival},
                (evryraice.platnom+1) time_kind
                from evryraice
                inner join buslst on (evryraice.buscod = buslst.buscod)
                inner join atplstall on (evryraice.atpcod = atplstall.atpcod)
                inner join naplst on (evryraice.awtcod = naplst.awtcod) and (evryraice.napcod = naplst.napcod)
                inner join tarifs2D on (evryraice.nomkat = TARIFS2D.katcode) and (evryraice.napcod = TARIFS2D.napcode)
                    and (evryraice.awtcod = TARIFS2D.awtcode)
                inner join traces on (TARIFS2D.awtcode = traces.awtcod) and (TARIFS2D.napcode = traces.napcod)
                    and (TARIFS2D.statcode = traces.statcod)
            where
                ((traces.statname = ?)
                and (traces.isdel = 0) and (TARIFS2D.paysoftall>0)
                and (evryraice.datestart = ?){$extraWhere}
                and (evryraice.awtcod = ?)
                and (evryraice.napcod = traces.napcod) and (evryraice.awtcod = traces.awtcod)
                and ((evryraice.tracetimecod is null))
                and (TARIFS2D.prim = 1)
                and RAICETYPCOD<>3
                and not((evryraice.canceled = 5))
                and TARIFS2D.startstatcode=0)
            order by 6, 7
        ";

        return $this->db->select($sql, [
            $stationName, $date, $awtcod,
            $stationName, $date, $awtcod,
        ]);
    }

    /** Все места рейса со статусами (KS_ALL_PLACES). */
    public function places(int $awtcod, string $date, int $napcod, int $raicecod): array
    {
        $rows = $this->db->select(
            'SELECT * FROM KS_ALL_PLACES(?,?,?,?)',
            [$date, $awtcod, $napcod, $raicecod],
        );

        $this->assertNoError($rows, 'Не удалось получить места рейса');

        return $rows;
    }

    /** Статус рейса (строка EVRYRAICE). */
    public function tripStatus(int $awtcod, string $date, int $napcod, int $raicecod): ?array
    {
        $rows = $this->db->select(
            'select * from evryraice where datestart=? and awtcod=? and napcod=? and raicecod=?',
            [$date, $awtcod, $napcod, $raicecod],
        );

        return $rows[0] ?? null;
    }

    /**
     * Резерв мест (KS_BRONTIME_PLACE, бронь ~24 мин c поправкой процедуры).
     *
     * @param  int[]  $places  конкретные места; пусто — любые $seatCount мест
     * @return array{id_time_places: string, places: string[], raw: array}
     */
    public function reserve(
        int $awtcod,
        int $napcod,
        int $raicecod,
        int $statcod,
        string $date,
        array $places = [],
        int $seatCount = 0,
    ): array {
        if ($places !== []) {
            $packedPlaces = implode('@', $places).'@';
            $count = count($places);
            $seatCount = 0;
        } else {
            $packedPlaces = '';
            $count = $seatCount;
        }

        $this->db->begin();

        try {
            $rows = $this->db->select(
                'SELECT * FROM KS_BRONTIME_PLACE(?,?,?,?,?,?,?,?,?,?,null,24)',
                [$packedPlaces, $seatCount, 0, $awtcod, $napcod, $raicecod, $statcod, $date, $this->agentCode, $this->workerCode],
            );

            $row = $rows[0] ?? null;

            $errCode = (int) ($row['ERR_CODE'] ?? $row['ERRORCODE'] ?? 1);
            if (! $row || $errCode !== 0 || empty($row['NN_PLACES_BRON_TRUE'])) {
                $reason = trim((string) ($row['ERR_TEXT'] ?? '')) ?: trim((string) ($row['COMMENT'] ?? '')) ?: 'нет свободных мест';
                throw new BusterException('Не удалось зарезервировать места: '.$reason, 'reserve_failed');
            }

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }

        $reserved = array_slice(array_filter(explode('@', $row['NN_PLACES_BRON_TRUE']), fn ($p) => $p !== ''), 0, $count);

        return [
            'id_time_places' => $row['ID_TIME_PLACES'],
            'places' => array_values($reserved),
            'raw' => $row,
        ];
    }

    /**
     * Продажа зарезервированных мест (KS_SALE_PLACES) + багаж
     * (SH0_SALE_BAG_ON_RAICE) + добровольная страховка (UPDATE SALE.STRWANT).
     *
     * @param  array[]  $tickets  каждый: place, type(П|Д), price, last_name,
     *                            first_name, middle_name, birth_date(Y-m-d),
     *                            doc_type, doc_series, doc_number, citizenship,
     *                            baggage, price_bag, insurance_price, comment
     * @return array{tickets: array[], raw: array}
     */
    public function sell(
        int $awtcod,
        int $napcod,
        int $raicecod,
        int $statcod,
        string $date,
        string $idTimePlaces,
        array $tickets,
    ): array {
        $marshKvit = '00130000009353285701'; // константа legacy (N_MARSH_KVIT)

        $packed = [
            'places' => '', 'places_kats' => '', 'f' => '', 'i' => '', 'o' => '',
            'dr' => '', 'doc_s' => '', 'doc_num' => '', 'doc' => '', 'grajd' => '',
            'pol' => '', 'comments' => '',
        ];
        $priceBag = '0.00';

        foreach ($tickets as $t) {
            $lastName = str_replace('Ё', 'Е', mb_substr(mb_strtoupper($t['last_name']), 0, 20));
            $firstName = str_replace('Ё', 'Е', mb_substr(mb_strtoupper($t['first_name']), 0, 14));
            $middleName = str_replace('Ё', 'Е', mb_substr(mb_strtoupper($t['middle_name'] ?? ''), 0, 14));

            // Лекарство от дублей (legacy): не продавать, если уже продано
            // это же место этому же пассажиру.
            $dup = $this->db->select(
                'SELECT FIRST 1 N_TICKET FROM SALE WHERE SALE.DATESTART=? AND SALE.NAPCOD=? AND SALE.RAICECOD=?'
                .' AND SALE.N_PLACE=? AND SALE.FIO_PAS=? AND SALE.TYPE_OPER=0 AND SALE.TICK_BACK=0'
                .' AND SALE.MAY_BACK=0 AND SALE.AWTCODSALE=?',
                [$date, $napcod, $raicecod, $t['place'], $lastName, $this->agentCode],
            );
            if ($dup !== []) {
                throw new BusterException('По этому заказу билеты уже оформлены', 'already_sold');
            }

            $packed['places'] .= $t['place'].'@';
            $packed['places_kats'] .= $t['type'].'@'.$t['price'].'@';
            $packed['f'] .= $lastName.'@';
            $packed['i'] .= $firstName.'@';
            $packed['o'] .= $middleName.'@';
            $packed['dr'] .= date('d.m.Y', strtotime($t['birth_date'])).'@';
            $packed['doc_s'] .= mb_substr($t['doc_series'] ?? '', 0, 6).'@';
            $packed['doc_num'] .= mb_substr($t['doc_number'], 0, 25).'@';
            $packed['doc'] .= ($t['doc_type'] ?? 21).'@';
            $packed['grajd'] .= ($t['citizenship'] ?? 185).'@';
            $packed['pol'] .= 'М@'; // legacy всегда 'М'
            $packed['comments'] .= mb_substr(str_replace('@', ';', $t['comment'] ?? ''), 0, 20).'@';

            if (! empty($t['price_bag'])) {
                $priceBag = (string) $t['price_bag'];
            }
        }

        // ID_TIME_PLACES — TIMESTAMP с долями секунды: инлайним литералом,
        // биндинг теряет доли и резерв «не находится» (LEGACY.md §13.4).
        $zakazLiteral = BusterConnection::quoteTimestamp($idTimePlaces);

        $this->db->begin();

        try {
            $rows = $this->db->select(
                'SELECT * FROM KS_SALE_PLACES(?,?,?,?,?,?,0,?,?,   ?,    ?,?,?,       ?,?,?, NULL,   NULL,  ?,NULL,?,?,NULL,NULL,?,?,NULL,NULL,?,?,'.$zakazLiteral.',?)',
                [
                    $packed['places'], $awtcod, $date, $napcod, $raicecod, $statcod,
                    0,                       // STRAX_SBOR
                    $priceBag,               // PRICE_BAG_1
                    $packed['places_kats'],
                    $packed['f'], $packed['i'], $packed['o'],
                    $packed['doc_s'], $packed['doc_num'], $packed['dr'], $packed['doc'],
                    $packed['grajd'], $packed['pol'],
                    $this->agentCode, $this->workerCode,
                    $marshKvit,
                    0,                       // COL_BAGS: багаж продаём отдельной процедурой
                    $packed['comments'],
                ],
            );

            $row = $rows[0] ?? null;

            if (! $row || empty($row['S_TICKETS'])) {
                throw new BusterException(
                    'Место оформить нельзя: '.($row['COMMENT_TXT'] ?? 'превышено время ожидания оплаты'),
                    'sale_failed',
                );
            }

            $numbers = $this->parseTickets($row['S_TICKETS']);

            $result = [];
            foreach (array_values($tickets) as $i => $t) {
                $sold = $t;
                $sold['place'] = $numbers[$i]['place'] ?? $t['place'];
                $sold['type'] = $numbers[$i]['type'] ?? $t['type'];
                $sold['serial'] = $numbers[$i]['serial'] ?? null;
                $sold['number'] = $numbers[$i]['number'] ?? null;
                $sold['baggage_tickets'] = [];

                // Страховка: STRWANT в SALE (legacy UPDATE после продажи).
                $insurance = (float) ($t['insurance_price'] ?? 0);
                if ($insurance > 0) {
                    $this->db->execute(
                        'UPDATE SALE SET STRWANT=? WHERE N_TICKET=? and DOB_N_TICKET=?',
                        [$insurance, $sold['number'], $sold['serial']],
                    );
                }

                // Багаж — отдельная процедура, в той же транзакции.
                $bagCount = (int) ($t['baggage'] ?? 0);
                if ($bagCount > 0) {
                    $bagRows = $this->db->select(
                        'SELECT * FROM SH0_SALE_BAG_ON_RAICE(?,?,?,?,?,?,?,?,   ?,    ?,?,?,       ?,?,?,?,?,?,?,?)',
                        [
                            $bagCount, $awtcod, $date, $napcod, $raicecod, 0, $statcod,
                            mb_substr($sold['last_name'], 0, 20).'@',
                            mb_substr($sold['first_name'], 0, 14).'@',
                            mb_substr($sold['middle_name'] ?? '', 0, 14).'@',
                            mb_substr($t['doc_series'] ?? '', 0, 6).'@',
                            mb_substr($t['doc_number'], 0, 25).'@',
                            date('d.m.Y', strtotime($t['birth_date'])).'@',
                            '', '',
                            ($t['doc_type'] ?? 21).'@',
                            $this->agentCode, $this->baggageWorkerCode,
                            $marshKvit,
                            (float) $priceBag,
                        ],
                    );

                    if (! $bagRows || empty($bagRows[0]['S_TICKETS'])) {
                        throw new BusterException('Не удалось оформить багаж', 'baggage_failed');
                    }

                    $sold['baggage_tickets'] = $this->parseTickets($bagRows[0]['S_TICKETS']);
                }

                $result[] = $sold;
            }

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }

        return ['tickets' => $result, 'raw' => $row];
    }

    /**
     * Возврат/аннулирование билетов (KS_ANN_TICKET_FROM_AW).
     *
     * @param  array[]  $tickets  каждый: serial, number
     */
    public function refund(int $awtcod, array $tickets, int $operation, bool $preview): array
    {
        $nTickets = '';
        $dobTickets = '';
        $conditions = [];

        foreach ($tickets as $t) {
            $nTickets .= $t['number'].'@';
            $dobTickets .= $t['serial'].'@';
            $conditions[] = '(N_TICKET='.((int) $t['number']).' and DOB_N_TICKET='.((int) $t['serial']).')';
        }

        // Билет нельзя вернуть после отправления рейса (IS_PRN).
        $sold = $this->db->select(
            'select * from SALE where ('.implode(' OR ', $conditions).") and TYPE_OPER=0 and datestart>'".date('Y-m-d', time() - 24 * 60 * 3600)."'",
        );

        foreach ($sold as $row) {
            if (! empty($row['IS_PRN'])) {
                throw new BusterException('Невозможно вернуть билет: рейс уже отправлен', 'trip_departed');
            }
        }

        $this->db->begin();

        try {
            $rows = $this->db->select(
                'SELECT * FROM KS_ANN_TICKET_FROM_AW(?,?,?,?,?,?,NULL)',
                [$nTickets, $dobTickets, $preview ? 1 : 0, $operation, $this->agentCode, $this->workerCode],
            );

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }

        $row = $rows[0] ?? [];

        $errCodes = array_filter(explode('@', (string) ($row['ERR_CODE_S'] ?? '')));
        $comments = explode('@', (string) ($row['COMMENT_FULL'] ?? ''));
        foreach ($errCodes as $i => $code) {
            if ((int) $code > 0) {
                throw new BusterException('Возврат отклонён: '.($comments[$i] ?? 'ошибка станции'), 'refund_rejected');
            }
        }

        return [
            'total_refund' => (float) ($row['SUMMA_BACK_FULL'] ?? 0),
            'places' => array_values(array_filter(explode('@', (string) ($row['N_PLACES'] ?? '')))),
            'prices' => array_values(array_filter(explode('@', (string) ($row['TICK_PRICE'] ?? '')))),
            'raw' => $row,
        ];
    }

    /** «3@П@082657229984@039900004@…» → [{place,type,serial,number}] */
    private function parseTickets(string $packed): array
    {
        $parts = explode('@', $packed);
        $out = [];

        for ($i = 0; $i + 3 < count($parts); $i += 4) {
            if ($parts[$i] === '' && $parts[$i + 1] === '') {
                continue;
            }
            $out[] = [
                'place' => $parts[$i],
                'type' => $parts[$i + 1],
                'serial' => $parts[$i + 2],
                'number' => $parts[$i + 3],
            ];
        }

        return $out;
    }

    private function assertNoError(array $rows, string $message): void
    {
        if ($rows && (int) ($rows[0]['ERRORCODE'] ?? 0) !== 0) {
            throw new BusterException($message.': '.($rows[0]['COMMENT'] ?? ''), 'buster_error');
        }
    }
}
