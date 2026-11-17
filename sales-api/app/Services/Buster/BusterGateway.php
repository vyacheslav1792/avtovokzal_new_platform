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
        /** Ручные ограничения продажи: [awtcod][napcod][время отправления] = statcod. */
        private readonly array $saleBounds = [],
    ) {}

    /**
     * Регулярное расписание станции — маршруты и график отправлений
     * (перенос legacy `ais/api/api2.php`, action=raspisanie).
     *
     * Условия выборки повторяют legacy 1:1: удалённые направления
     * (naplst.isdel), коммерческие рейсы (RAICETYPCOD=3 — они нужны
     * только для подсчёта проездных) и снятые с регулярки (reg_stop)
     * в расписание не попадают. Против legacy добавлены только поля,
     * которых там не выводили: перевозчик, номер и название маршрута.
     */
    public function regularSchedule(int $awtcod): array
    {
        return $this->db->select(
            'select naplst.napname, naplst.napcod, raicelst.*, atplstall.ATPOFFICIAL as atpname
               from raicelst
               left join naplst on naplst.napcod = raicelst.napcod
               left join atplstall on atplstall.atpcod = raicelst.atpcod
              where naplst.isdel = 0
                and raicelst.RAICETYPCOD <> 3
                and raicelst.awtcod = ?
                and (raicelst.reg_stop is null or raicelst.reg_stop >= current_date)
              order by raicelst.raicename, naplst.napname, raicelst.timestart',
            [$awtcod],
        );
    }

    /** Карточка рейса регулярного расписания (для страницы тарифа). */
    public function routeCard(int $awtcod, int $napcod, int $raicecod): ?array
    {
        $rows = $this->db->select(
            'select raicelst.raicename, raicelst.marshnom, raicelst.marshname, raicelst.timestart,
                    raicelst.chandate, raicelst.oldnomkat, raicelst.newnomkat, raicelst.raice_category,
                    naplst.napname, atplstall.ATPOFFICIAL as atpname
               from raicelst
               left join naplst on naplst.napcod = raicelst.napcod and naplst.awtcod = raicelst.awtcod
               left join atplstall on atplstall.atpcod = raicelst.atpcod
              where raicelst.awtcod = ? and raicelst.napcod = ? and raicelst.raicecod = ?',
            [$awtcod, $napcod, $raicecod],
        );

        return $rows[0] ?? null;
    }

    /**
     * Тарифы по остановкам рейса (перенос legacy action=tarif).
     *
     * Дубль-запрос legacy (там он выполнялся ещё раз при пустом результате,
     * отличаясь только регистром имени таблицы) не переносим — запросы
     * идентичны, второй никогда ничего не добавлял.
     */
    public function tariff(int $napcod, int $nomkat, int $raicecod): array
    {
        return $this->db->select(
            'select raicelst.chandate, raicelst.newnomkat, raicelst.oldnomkat, raicelst.timestart,
                    tracetime.time_to_stat, tracetime.time_of_stop,
                    traces.statname, traces.kilom,
                    TARIFS2D.statcode, TARIFS2D.PAYHARDALL, TARIFS2D.DOBRSTR,
                    TARIFS2D.payhardchd, TARIFS2D.payhardbag
               from tarifs2D
               left join traces on (traces.napcod = tarifs2D.napcode and traces.statcod = tarifs2D.statcode)
               left join raicelst on (raicelst.napcod = TARIFS2D.napcode)
               left join tracetime on (tracetime.napcod = traces.napcod
                    and raicelst.tracetimecod = tracetime.traicetimecod
                    and traces.statcod = tracetime.statcod)
              where PAYHARDALL > 0
                and TARIFS2D.napcode = ?
                and tarifs2D.katcode = ?
                and raicelst.raicecod = ?
                and TARIFS2D.startstatcode = 0
              order by traces.kilom',
            [$napcod, $nomkat, $raicecod],
        );
    }

    /** Даты нерегулярных рейсов станции (REG_FIXED): reg_fixed_days. */
    public function fixedDays(int $awtcod): array
    {
        return $this->db->select(
            'select * from reg_fixed_days where awtcod = ? order by DATESTART',
            [$awtcod],
        );
    }

    /** Пункты, до которых продаются билеты со станции (KS_STATIONS). */
    public function destinations(int $awtcod): array
    {
        return $this->db->select(
            'SELECT STAT_NAME AS STATION_NAME, PRE_SALE_DAYS AS PRE_SALES_DAY FROM KS_STATIONS (0, ?, 0, ?)',
            [$awtcod, $awtcod],
        );
    }

    /**
     * Рейсы до станции назначения (по имени станции в TRACES).
     *
     * $date = null — режим «ближайшие» legacy (blij=1 на raspisanie-avtobusov):
     * рейсы всех дат начиная с сегодняшней, не больше 200 строк.
     */
    public function routes(int $awtcod, string $stationName, ?string $date, bool $forSale = true): array
    {
        $today = date('Y-m-d');
        $nearest = $date === null;
        $dateWhere = $nearest ? 'evryraice.datestart >= ?' : 'evryraice.datestart = ?';
        $dateParam = $nearest ? $today : $date;
        // legacy ставил "first 200" только в режиме «ближайшие» — на дату
        // рейсов заведомо меньше.
        $limit = $nearest ? 'first 200' : '';

        $extraWhere = '';
        if ($forSale) {
            $extraWhere = ' and available=1 and evryraice.transit=0';
            $notSoon = date('H:i:s', time() + 2000);
            if ($date === $today) {
                // Не показываем рейсы, до отправления которых < ~33 минут.
                $extraWhere .= " and timestart>'{$notSoon}'";
            } elseif ($nearest) {
                // Та же отсечка, но выборка идёт по нескольким датам, поэтому
                // время отсекаем только у сегодняшних рейсов. (В legacy этой
                // комбинации не было: «ближайшие» открывались лишь со страницы
                // расписания, где показывались все рейсы подряд.)
                $extraWhere .= " and (evryraice.datestart>'{$today}' or timestart>'{$notSoon}')";
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
                (evryraice.martypcod * naplst.reg_pasp_data) reg_pas_data,
                %TIME_WAY% time_way_minutes
SQL;

        /*
         * ВРЕМЯ В ПУТИ (минуты) отдаём отдельным полем, и без него
         * прибытие посчитать НЕЛЬЗЯ: `time_to_end` — это TIME, то есть
         * время суток, и Firebird при сложении оборачивает его через
         * 24 часа. У рейса Орёл–Симферополь в пути 1535 минут
         * (25 ч 35 мин), и 11:15 + 25:35 даёт 12:50 — то же самое, что
         * «прибытие в 12:50 в день отправления». Сайт из этого делал
         * вывод «прибытие раньше отправления? плюс сутки», а раз
         * 12:50 > 11:15, сутки не добавлялись вовсе: пассажиру
         * показывалось 18.11 вместо 19.11.
         *
         * Источник — `tracetime.time_to_stat`, та же величина, по которой
         * считается «время в пути» на странице тарифа. У рейсов без
         * расписания остановок (вторая ветка UNION, `tracetimecod is
         * null`) её нет: там и `time_to_end` равен времени отправления,
         * то есть прибытие неизвестно в принципе.
         */
        $fieldsWithArrival = str_replace(
            ['%TIME_TO_END%', '%TIME_WAY%'],
            ['(evryraice.timestart + tracetime.time_to_stat*60)', 'tracetime.time_to_stat'],
            $fields,
        );
        $fieldsNoArrival = str_replace(
            ['%TIME_TO_END%', '%TIME_WAY%'],
            ['evryraice.timestart', 'cast(null as integer)'],
            $fields,
        );

        $sql = "
            select {$limit} {$fieldsWithArrival},
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
                and ({$dateWhere}){$extraWhere}
                and (evryraice.awtcod = ?)
                and (evryraice.napcod = traces.napcod) and (evryraice.awtcod = traces.awtcod)
                and (not (evryraice.tracetimecod is null) and (evryraice.tracetimecod = tracetime.traicetimecod))
                and (TARIFS2D.prim = 1)
                and RAICETYPCOD<>3
                and not((evryraice.canceled = 5))
                and TARIFS2D.startstatcode=0)
            /*
             * ВТОРАЯ ВЕТВЬ — рейсы, у которых времени в пути до этой
             * остановки НЕТ.
             *
             * ДЕФЕКТ LEGACY, который здесь исправлен: в legacy
             * (`ais/api/api2.php`) условие было
             * `evryraice.tracetimecod is null`, то есть вторая ветвь
             * ловила только рейсы вообще без расписания остановок. А
             * первая требует строку `tracetime` ИМЕННО для запрошенной
             * остановки (`left outer join` + условие в `where` работает
             * как inner). Между ними оставалась мёртвая зона: у рейса
             * расписание есть (`tracetimecod` задан), но строки для этой
             * остановки в нём нет — и рейс не попадал в список ВООБСЕ,
             * хотя билет до неё продаётся (`TARIFS2D.paysoftall > 0`,
             * `prim = 1`).
             *
             * На боевых данных станции Орел за 18.11.2026 так выпадали
             * 78 пар «рейс × остановка» из 6506 — например «Орел →
             * Дв.спорта» не показывал ни одного рейса до Моховицы,
             * «Орел → Госуниверситет-УНПК» — до Маслово.
             *
             * Поэтому условие заменено на «подходящей строки tracetime
             * нет»: оно покрывает и прежний случай (`tracetimecod is
             * null` — тогда равенство с ним ложно для любой строки), и
             * мёртвую зону. Ветви остаются взаимоисключающими: первая
             * требует существования строки, вторая — её отсутствия.
             *
             * Прибытие у таких рейсов неизвестно: `time_to_end` равен
             * времени отправления, `time_way_minutes` — null, и сайт
             * обязан писать «уточняйте на станции», а не выдавать время
             * отправления за время прибытия.
             */
            union
            select {$limit} {$fieldsNoArrival},
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
                and ({$dateWhere}){$extraWhere}
                and (evryraice.awtcod = ?)
                and (evryraice.napcod = traces.napcod) and (evryraice.awtcod = traces.awtcod)
                and (not exists (
                    select 1 from tracetime tt
                    where tt.awtcod = traces.awtcod and tt.napcod = traces.napcod
                      and tt.statcod = traces.statcod
                      and tt.traicetimecod = evryraice.tracetimecod
                ))
                and (TARIFS2D.prim = 1)
                and RAICETYPCOD<>3
                and not((evryraice.canceled = 5))
                and TARIFS2D.startstatcode=0)
            order by 6, 7
        ";

        $rows = $this->db->select($sql, [
            $stationName, $dateParam, $awtcod,
            $stationName, $dateParam, $awtcod,
        ]);

        return $this->applySaleBounds($awtcod, $rows);
    }

    /**
     * Ограничения продажи по дальности (перенос legacy api2.php после выборки
     * рейсов): SALE_BOUND_AFTER даёт остановку, БЛИЖЕ которой продавать нельзя
     * (MIN_KILOM), SALE_BOUND_BEFORE — ДАЛЬШЕ которой нельзя (MAX_KILOM).
     * Ограничение показывается, только если пункт назначения запроса реально
     * попадает под него — сравниваем километраж по TRACES.
     *
     * Ручной перекрыватель SALE_BOUND_AFTER (в legacy — файл
     * `avtovokzal57/vidjets/ogranichenie.txt`, читался в config.php) перенесён
     * в `config/sale_bounds.php`.
     */
    private function applySaleBounds(int $awtcod, array $rows): array
    {
        $traces = [];
        $trace = function (int $napcod, int $statcod) use ($awtcod, &$traces): ?array {
            $key = $napcod.':'.$statcod;
            if (! array_key_exists($key, $traces)) {
                $found = $this->db->select(
                    'select statname, kilom from traces where isdel = 0 and statcod = ? and awtcod = ? and napcod = ?',
                    [$statcod, $awtcod, $napcod],
                );
                $traces[$key] = $found[0] ?? null;
            }

            return $traces[$key];
        };

        foreach ($rows as $i => $row) {
            $napcod = (int) $row['NAPCOD'];
            $rows[$i]['MIN_KILOM'] = null;
            $rows[$i]['MAX_KILOM'] = null;

            $after = (int) ($this->saleBounds[$awtcod][$napcod][$row['TIMESTART']] ?? $row['SALE_BOUND_AFTER'] ?? 0);
            $before = (int) ($row['SALE_BOUND_BEFORE'] ?? 0);
            if (! $after && ! $before) {
                continue;
            }

            $destination = $trace($napcod, (int) $row['STATCODE']);
            if (! $destination) {
                continue;
            }

            if ($after) {
                $bound = $trace($napcod, $after);
                if ($bound && $bound['STATNAME'] !== null && (float) $destination['KILOM'] < (float) $bound['KILOM']) {
                    $rows[$i]['MIN_KILOM'] = $bound['STATNAME'];
                }
            }

            if ($before) {
                $bound = $trace($napcod, $before);
                if ($bound && $bound['STATNAME'] !== null && (float) $destination['KILOM'] > (float) $bound['KILOM']) {
                    $rows[$i]['MAX_KILOM'] = $bound['STATNAME'];
                }
            }
        }

        return $rows;
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
     * Снять бронь с мест (KS_ABO_BRONTIME_PLACES).
     *
     * Процедура убирает пометку TimeMark с перечисленных мест рейса — та же,
     * которой `KS_BRONTIME_PLACE` откатывает частично удавшуюся бронь. Снять
     * бронь может **только тот агент, кто её ставил** (для этого в процедуру
     * идут AGENT_CODE и AGENT_WRK_CODE), поэтому чужие места ею не освободить.
     *
     * @param  int[]  $places  номера мест
     * @return int сколько мест удалось освободить
     */
    public function releaseReservation(
        int $awtcod,
        int $napcod,
        int $raicecod,
        string $date,
        array $places,
    ): int {
        if ($places === []) {
            return 0;
        }

        $packedPlaces = implode('@', $places).'@';

        $this->db->begin();

        try {
            $rows = $this->db->select(
                'SELECT COL_ABO_BRON FROM KS_ABO_BRONTIME_PLACES(?,?,?,?,?,?,?)',
                [$date, $awtcod, $napcod, $raicecod, $this->agentCode, $this->workerCode, $packedPlaces],
            );

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }

        return (int) ($rows[0]['COL_ABO_BRON'] ?? 0);
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
                // Станция возвращает причину уже с собственным вступлением
                // («место оформить нельзя: …»), поэтому своё добавляем
                // только когда причины нет — иначе текст удваивался.
                $reason = trim((string) ($row['COMMENT_TXT'] ?? ''));

                throw new BusterException(
                    $reason !== ''
                        ? mb_strtoupper(mb_substr($reason, 0, 1)).mb_substr($reason, 1)
                        : 'Место оформить нельзя: превышено время ожидания оплаты',
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

            throw $this->translateConflict($e);
        }

        return ['tickets' => $result, 'raw' => $row];
    }

    /**
     * Дедлок Firebird при одновременной продаже одного и того же места —
     * это «продажа уже идёт», а не сбой базы.
     *
     * Ловилось на проде 15.11.2026: страница результата прислала два
     * `orders/confirm` в одну секунду, второй KS_SALE_PLACES получил
     * `-913 deadlock … At procedure 'KSR_SALONINFO_IU'`, а backend принял
     * безликий «Server Error» за настоящую неудачу оформления и записал
     * заказу `failed` ПОВЕРХ уже оформленного. С отдельным кодом вызывающая
     * сторона такую ошибку отличает и заказ не портит.
     */
    private function translateConflict(\Throwable $e): \Throwable
    {
        $text = $e->getMessage();

        if (str_contains($text, '-913')
            || str_contains($text, '40001')
            || stripos($text, 'deadlock') !== false
            || stripos($text, 'concurrent update') !== false
            || stripos($text, 'lock conflict') !== false
        ) {
            return new BusterException(
                'Оформление этих мест уже выполняется, повторите запрос',
                'concurrent_sale',
            );
        }

        return $e;
    }

    /**
     * Возврат/аннулирование билетов (KS_ANN_TICKET_FROM_AW).
     *
     * @param  array[]  $tickets  каждый: serial, number
     * @param  bool  $adminMode  админский возврат (legacy admin_mode):
     *                           разрешён и после отправления рейса (IS_PRN)
     */
    public function refund(
        int $awtcod,
        array $tickets,
        int $operation,
        bool $preview,
        bool $adminMode = false,
        ?string $backdate = null,
    ): array {
        $nTickets = '';
        $dobTickets = '';
        $conditions = [];

        foreach ($tickets as $t) {
            $nTickets .= $t['number'].'@';
            $dobTickets .= $t['serial'].'@';
            $conditions[] = '(N_TICKET='.((int) $t['number']).' and DOB_N_TICKET='.((int) $t['serial']).')';
        }

        // Билет нельзя вернуть после отправления рейса (IS_PRN) —
        // кроме админского режима (legacy: IS_PRN && !admin_mode).
        $sold = $this->db->select(
            'select * from SALE where ('.implode(' OR ', $conditions).") and TYPE_OPER=0 and datestart>'".date('Y-m-d', time() - 24 * 60 * 3600)."'",
        );

        foreach ($sold as $row) {
            if (! empty($row['IS_PRN']) && ! $adminMode) {
                throw new BusterException('Невозможно вернуть билет: рейс уже отправлен', 'trip_departed');
            }
        }

        // Время станции ДО вызова: по нему потом находим записи, которые
        // создала именно эта операция (у SALE нет суррогатного ключа —
        // первичный ключ составной и начинается с даты и времени продажи).
        $startedAt = $backdate === null || $preview ? null : $this->stationNow();

        /*
         * ОТКАЗ ХОТЯ БЫ ПО ОДНОМУ БЛАНКУ ОТКАТЫВАЕТ ВСЮ ОПЕРАЦИЮ.
         *
         * KS_ANN_TICKET_FROM_AW идёт по бланкам в ЦИКЛЕ и обрабатывает
         * каждый независимо: удачные аннулирует, по неудачным дописывает
         * «#» и свой код в ERR_CODE_S (см. docs/buster-procedures/
         * KS_ANN_TICKET_FROM_AW.sql). Пока проверка ERR_CODE_S стояла
         * ПОСЛЕ commit(), частичный успех оставался на станции, а наружу
         * уходил полный отказ — то есть бланк аннулирован, а записи о
         * возврате и денег покупателю нет.
         *
         * Ровно так и вышло на проде 17.11.2026 (заказ
         * c57035943d07716aebbe4384f0819f, билет 112657019984/099900079,
         * АДАМЕНКО): на станцию ушли билет и его багажная квитанция,
         * квитанция была аннулирована минутой раньше отдельной операцией,
         * процедура отказала по ней — и мы сообщили «Билет не найден в БД
         * вокзала», уже аннулировав САМ БИЛЕТ. Место освободилось, 6935 ₽
         * у покупателя не вернулись, карточка возврата встала в тупик.
         *
         * Автономных транзакций процедура не открывает, поэтому rollBack
         * снимает и то, что она успела записать.
         */
        $this->db->begin();

        try {
            $rows = $this->db->select(
                'SELECT * FROM KS_ANN_TICKET_FROM_AW(?,?,?,?,?,?,NULL)',
                [$nTickets, $dobTickets, $preview ? 1 : 0, $operation, $this->agentCode, $this->workerCode],
            );

            $row = $rows[0] ?? [];
            $blanks = $this->refundBlankResults($tickets, $row);
            $rejected = array_values(array_filter($blanks, fn (array $b) => ! $b['ok']));

            if ($rejected !== []) {
                $this->db->rollBack();

                throw new BusterException(
                    $this->refundRejectionMessage($rejected, $blanks),
                    'refund_rejected',
                    ['blanks' => $blanks],
                );
            }

            $this->db->commit();
        } catch (BusterException $e) {
            // Откат уже сделан там, где отказ обнаружен.
            throw $e;
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }

        $backdated = null;

        if ($startedAt !== null) {
            $backdated = $this->backdateRefundRows($tickets, $startedAt, $backdate);
        }

        $marshParts = array_values(array_filter(explode('@', (string) ($row['MARSH_NAME'] ?? ''))));
        $dateTimeStart = array_values(array_filter(explode('@', (string) ($row['DATE_TIME_START'] ?? ''))));

        return [
            'total_refund' => (float) ($row['SUMMA_BACK_FULL'] ?? 0),
            'places' => array_values(array_filter(explode('@', (string) ($row['N_PLACES'] ?? '')))),
            'prices' => array_values(array_filter(explode('@', (string) ($row['TICK_PRICE'] ?? '')))),
            // Проценты удержания по каждому билету (TICK_SHTRAFPROC: «25@25@»)
            'penalties' => array_values(array_filter(
                explode('@', (string) ($row['TICK_SHTRAFPROC'] ?? '')),
                fn ($v) => $v !== '',
            )),
            'trip_name' => implode('–', array_slice($marshParts, 0, 2)),
            'datetime_start' => $dateTimeStart[0] ?? null,
            // Итог переноса даты возврата в журнале станции: сколько
            // записей сдвинуто и почему не вышло (null — не просили).
            'backdate' => $backdated,
            /*
             * Результат ПО КАЖДОМУ бланку в порядке запроса. Нужен
             * вызывающей стороне: сумма возврата признаком успеха быть не
             * может (станция честно отдаёт 0 за бесплатный багаж и при
             * полном удержании), а «все бланки ok» — может.
             */
            'blanks' => $blanks,
            'raw' => $row,
        ];
    }

    /**
     * Разобрать ответ процедуры в результат по каждому бланку.
     *
     * ERR_CODE_S и COMMENT_FULL — строки «значение@значение@» в порядке
     * переданных бланков, по одному элементу на бланк. `array_filter` без
     * колбэка здесь применять нельзя: он выбрасывает нули, то есть ровно
     * признак успеха.
     *
     * @param  array[]  $tickets
     * @param  array<string, mixed>  $row
     * @return array<int, array<string, mixed>>
     */
    private function refundBlankResults(array $tickets, array $row): array
    {
        $codes = explode('@', (string) ($row['ERR_CODE_S'] ?? ''));
        $comments = explode('@', (string) ($row['COMMENT_FULL'] ?? ''));
        $prices = explode('@', (string) ($row['TICK_PRICE'] ?? ''));
        $percents = explode('@', (string) ($row['TICK_SHTRAFPROC'] ?? ''));
        $places = explode('@', (string) ($row['N_PLACES'] ?? ''));

        $result = [];

        foreach (array_values($tickets) as $i => $t) {
            $code = isset($codes[$i]) && trim($codes[$i]) !== '' ? (int) $codes[$i] : null;
            $ok = $code === 0;

            $result[] = [
                'serial' => (string) $t['serial'],
                'number' => (string) $t['number'],
                'ok' => $ok,
                'error_code' => $code,
                'error' => $ok ? null : (trim((string) ($comments[$i] ?? '')) ?: 'станция отклонила бланк'),
                'place' => $ok ? (trim((string) ($places[$i] ?? '')) ?: null) : null,
                'price' => $ok && isset($prices[$i]) && is_numeric($prices[$i]) ? (float) $prices[$i] : null,
                'penalty_percent' => $ok && isset($percents[$i]) && is_numeric($percents[$i])
                    ? (int) $percents[$i]
                    : null,
            ];
        }

        /*
         * Процедура не вернула код ни по одному бланку — считать это
         * успехом нельзя: на станции могло не произойти ничего.
         */
        if ($result !== [] && ! array_filter($result, fn (array $b) => $b['error_code'] !== null)) {
            foreach ($result as $i => $b) {
                $result[$i]['ok'] = false;
                $result[$i]['error'] = 'станция не вернула результат по бланку';
            }
        }

        return $result;
    }

    /**
     * Текст отказа, в котором НАЗВАН БЛАНК.
     *
     * «Билет не найден в БД вокзала» без номера бланка читается про тот
     * билет, который кассир и возвращает, — а относиться может к его
     * багажной квитанции (так и случилось 17.11.2026). Поэтому в
     * сообщении всегда стоит серия и номер отклонённого бланка.
     *
     * @param  array<int, array<string, mixed>>  $rejected
     * @param  array<int, array<string, mixed>>  $blanks
     */
    private function refundRejectionMessage(array $rejected, array $blanks): string
    {
        $parts = array_map(
            fn (array $b) => $b['serial'].'/'.$b['number'].' — '.$b['error'],
            $rejected,
        );

        $message = 'Возврат отклонён станцией по '
            .(count($parts) > 1 ? 'бланкам: ' : 'бланку: ').implode('; ', $parts);

        // Операция откатывается целиком, и сказать об этом надо прямо:
        // иначе кассир будет искать, что же из бланков всё-таки прошло.
        if (count($blanks) > 1) {
            $message .= '. Операция отменена полностью — остальные бланки заказа не тронуты.';
        }

        return $message;
    }

    /**
     * Состояние бланков в журнале станции: продан, возвращён, кем и когда.
     *
     * Зачем нужен отдельный запрос, а не наша таблица возвратов: запись о
     * возврате у нас может ОТСУТСТВОВАТЬ при аннулированном на станции
     * бланке — так и случилось 17.11.2026, когда отказ по одному бланку
     * отменил запись возврата, но не отменил аннулирование (см. refund()).
     * Кроме того бланк мог вернуть кассир станции своими руками, и тогда
     * деньги покупателю уже выданы — второй возврат на карту делать
     * нельзя.
     *
     * `AWTCODSALE` в SALE — это код агента, и он отличает нас от прочих
     * надёжно: 9984 — сайт, 5701 — кассы станции Орла, 9900 — другой
     * интернет-агент (проверено на боевых возвратах).
     *
     * @param  array[]  $blanks  каждый: serial, number
     * @param  string|null  $dateStart  дата рейса — сужает выборку по
     *                                  индексируемому DATESTART; без неё
     *                                  запрос идёт по всему журналу
     * @return array<int, array<string, mixed>>
     */
    public function blankState(array $blanks, ?string $dateStart = null): array
    {
        if ($blanks === []) {
            return [];
        }

        $conditions = [];

        foreach ($blanks as $b) {
            $conditions[] = '(N_TICKET='.((int) $b['number']).' and DOB_N_TICKET='.((int) $b['serial']).')';
        }

        $sql = 'select DATESALE, TIMESALE, AWTCODSALE, WRKCOD, TYPE_OPER, N_TICKET, DOB_N_TICKET,
                       N_PLACE, PRICE_PLACE, PRICE_PL_ISX, PROC_BACK, DATESTART, KATPAS, TYPE_TICK,
                       FIO_PAS, NAME1_PASS, NAME2_PASS, IS_PRN, INTERNET_SALE
                from SALE
                where ('.implode(' OR ', $conditions).')';
        $bindings = [];

        if ($dateStart !== null) {
            $sql .= ' and DATESTART = ?';
            $bindings[] = $dateStart;
        }

        $sql .= ' order by DATESALE, TIMESALE';

        $rows = $this->db->select($sql, $bindings);

        $result = [];

        foreach ($blanks as $b) {
            $serial = (string) $b['serial'];
            $number = (string) $b['number'];

            $own = array_values(array_filter(
                $rows,
                fn (array $r) => (int) $r['DOB_N_TICKET'] === (int) $serial
                    && (int) $r['N_TICKET'] === (int) $number,
            ));

            $sold = null;
            $annulments = [];

            foreach ($own as $r) {
                if ((int) ($r['TYPE_OPER'] ?? 0) === 0) {
                    $sold = [
                        'date' => trim((string) $r['DATESALE']),
                        'time' => trim((string) $r['TIMESALE']),
                        'place' => trim((string) $r['N_PLACE']) ?: null,
                        'price' => (float) $r['PRICE_PLACE'],
                        'price_original' => (float) $r['PRICE_PL_ISX'],
                        'date_start' => trim((string) $r['DATESTART']),
                        'passenger' => trim(implode(' ', array_filter([
                            trim((string) $r['FIO_PAS']),
                            trim((string) $r['NAME1_PASS']),
                            trim((string) $r['NAME2_PASS']),
                        ]))) ?: null,
                        // Категория 9 — багажная квитанция, самостоятельный бланк.
                        'kind' => (int) ($r['KATPAS'] ?? 0) === 9 ? 'baggage' : 'ticket',
                        'agent_code' => (int) $r['AWTCODSALE'],
                        'worker_code' => (int) $r['WRKCOD'],
                        'internet_sale' => (bool) ($r['INTERNET_SALE'] ?? false),
                        // Рейс отправлен: станция возврат уже не разрешит.
                        'is_printed' => (bool) ($r['IS_PRN'] ?? false),
                        'by_our_agent' => (int) $r['AWTCODSALE'] === $this->agentCode,
                    ];

                    continue;
                }

                $annulments[] = [
                    'date' => trim((string) $r['DATESALE']),
                    'time' => trim((string) $r['TIMESALE']),
                    'type_oper' => (int) $r['TYPE_OPER'],
                    'price' => (float) $r['PRICE_PLACE'],
                    'penalty_percent' => (int) ($r['PROC_BACK'] ?? 0),
                    'agent_code' => (int) $r['AWTCODSALE'],
                    'worker_code' => (int) $r['WRKCOD'],
                    // Аннулировали МЫ (наш агент) или касса станции: во
                    // втором случае деньги, скорее всего, выданы там.
                    'by_our_agent' => (int) $r['AWTCODSALE'] === $this->agentCode,
                ];
            }

            $result[] = [
                'serial' => $serial,
                'number' => $number,
                'found' => $own !== [],
                'sold' => $sold,
                'annulled' => $annulments !== [],
                // Последняя операция возврата — она и описывает текущее состояние.
                'annulment' => $annulments === [] ? null : $annulments[count($annulments) - 1],
                'annulments' => $annulments,
            ];
        }

        return $result;
    }

    /** Текущие дата и время ПО ЧАСАМ СТАНЦИИ, а не нашего сервера. */
    private function stationNow(): string
    {
        $row = $this->db->select('select cast(current_timestamp as timestamp) as now from rdb$database');

        return (string) ($row[0]['NOW'] ?? date('Y-m-d H:i:s'));
    }

    /**
     * Перенести дату возврата в журнале станции на дату, когда деньги
     * покупателю реально вернули (возврат при недоступной станции: деньги
     * отдали сразу, аннулирование дослали позже).
     *
     * ЧТО ВАЖНО ЗНАТЬ ПРО ЭТУ ПРАВКУ:
     *  - `DATESALE` и `TIMESALE` входят в ПЕРВИЧНЫЙ КЛЮЧ SALE, то есть это
     *    изменение ключа. Внешних ключей на SALE в базе нет, поэтому связи
     *    не рвутся, но при совпадении ключа с существующей записью
     *    обновление будет отклонено — тогда просто оставляем дату как есть;
     *  - финансовые итоги станции (КСР) пишет триггер SALE_FINKSR_AI0 при
     *    ВСТАВКЕ записи и по дате вставки; UPDATE их не переносит
     *    (UPDATE-триггер финитогов срабатывает лишь в узком случае
     *    kkm_ok=0, recmove=2, type_tick=6). Значит журнал возвратов
     *    сдвинется, а финитоги останутся на дате фактического
     *    аннулирования — расхождение, о котором сказано в описании
     *    выключателя в АИС;
     *  - правим ТОЛЬКО записи, созданные этим вызовом: отбираем по номерам
     *    бланков, типу операции (возврат) и времени не раньше начала
     *    операции. Старые возвраты и чужие записи не трогаем.
     *
     * @param  array<int, array<string, mixed>>  $tickets
     * @return array<string, mixed>
     */
    private function backdateRefundRows(array $tickets, string $startedAt, string $target): array
    {
        $date = date('Y-m-d', strtotime($target));
        $time = date('H:i:s', strtotime($target));

        $updated = 0;
        $errors = [];

        foreach ($tickets as $t) {
            /*
             * «Не раньше начала операции» сравниваем датой и временем
             * ОТДЕЛЬНО: в SALE это два поля (DATE и TIME), а складывать
             * timestamp со time Firebird 2.5 не умеет.
             */
            $rows = $this->db->select(
                'select DATESALE, TIMESALE, AWTCODSALE, WRKCOD, TYPE_OPER, N_TICKET, DOB_N_TICKET, DATESTART
                 from SALE
                 where N_TICKET = ? and DOB_N_TICKET = ? and TYPE_OPER <> 0
                   and DATESALE >= ? and (DATESALE > ? or TIMESALE >= ?)',
                [
                    (int) $t['number'],
                    (int) $t['serial'],
                    date('Y-m-d', strtotime($startedAt)),
                    date('Y-m-d', strtotime($startedAt)),
                    date('H:i:s', strtotime($startedAt)),
                ],
            );

            foreach ($rows as $row) {
                try {
                    $this->db->begin();
                    $this->db->execute(
                        'update SALE set DATESALE = ?, TIMESALE = ?
                         where DATESALE = ? and TIMESALE = ? and AWTCODSALE = ? and WRKCOD = ?
                           and TYPE_OPER = ? and N_TICKET = ? and DOB_N_TICKET = ? and DATESTART = ?',
                        [
                            $date, $time,
                            $row['DATESALE'], $row['TIMESALE'], $row['AWTCODSALE'], $row['WRKCOD'],
                            $row['TYPE_OPER'], $row['N_TICKET'], $row['DOB_N_TICKET'], $row['DATESTART'],
                        ],
                    );
                    $this->db->commit();
                    $updated++;
                } catch (\Throwable $e) {
                    $this->db->rollBack();
                    $errors[] = mb_substr($e->getMessage(), 0, 180);
                }
            }
        }

        return [
            'requested' => $target,
            'updated_rows' => $updated,
            'errors' => $errors,
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
