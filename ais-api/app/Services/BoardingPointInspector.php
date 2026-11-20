<?php

namespace App\Services;

use Throwable;

/**
 * Проверка привязки пункта посадки к станционной базе: есть ли такая
 * остановка, куда от неё продаются билеты и не запрещена ли посадка.
 *
 * Нужна ровно потому, что станция об этих условиях МОЛЧИТ: процедура
 * резерва с кодом остановки без тарифа отвечает «ошибок нет», бронирует
 * место и отдаёт нулевую цену, а при запрещённой посадке
 * (`TRACETIME.SET_DISABLED = 1`) не возражает вовсе. Поэтому сотрудник,
 * который заводит пункт посадки, должен видеть состояние НАСТРОЕК
 * станции до того, как остановка появится на сайте.
 * Разбор — docs/BOARDING_POINTS.md.
 */
class BoardingPointInspector
{
    public function __construct(
        private readonly StationRegistry $stations,
        private readonly SiteSettings $settings,
    ) {}

    /**
     * Названия остановок станции — подсказка при вводе привязки.
     *
     * Одно название встречается в нескольких направлениях, поэтому
     * `distinct`: сотруднику важно название, направления подберёт поиск
     * рейсов.
     *
     * @return array<int, string>
     */
    public function stops(int $awtcod, string $search = ''): array
    {
        $station = $this->station($awtcod);

        $sql = 'select distinct tr.statname
                  from traces tr
                  inner join naplst n on (n.awtcod = tr.awtcod) and (n.napcod = tr.napcod) and (n.isdel = 0)
                 where tr.awtcod = ? and tr.isdel = 0';
        $params = [$awtcod];

        if ($search !== '') {
            $sql .= ' and upper(tr.statname) like ?';
            $params[] = '%'.mb_strtoupper($search).'%';
        }

        $sql .= ' order by 1';

        $rows = $this->connect($station)->select($sql, $params);

        return array_map(fn (array $row) => (string) $row['STATNAME'], $rows);
    }

    /**
     * Состояние привязки: пункты назначения, доступные от остановки, и
     * предупреждения по настройкам станции.
     *
     * @return array{
     *     ok: bool,
     *     error: ?string,
     *     directions: array<int, array{napcod: int, napname: string, statcod: int, kilom: float}>,
     *     destinations: array<int, array{name: string, directions: int}>,
     *     boarding: array{allowed: int, denied: int, no_stop_time: int},
     *     warnings: array<int, string>,
     * }
     */
    public function inspect(int $awtcod, string $stationName): array
    {
        $result = [
            'ok' => false,
            'error' => null,
            'directions' => [],
            'destinations' => [],
            'boarding' => ['allowed' => 0, 'denied' => 0, 'no_stop_time' => 0],
            'warnings' => [],
        ];

        try {
            $station = $this->station($awtcod);
            $db = $this->connect($station);
        } catch (Throwable $e) {
            $result['error'] = $e->getMessage();

            return $result;
        }

        try {
            $result['directions'] = array_map(fn (array $row) => [
                'napcod' => (int) $row['NAPCOD'],
                'napname' => (string) $row['NAPNAME'],
                'statcod' => (int) $row['STATCOD'],
                'kilom' => (float) $row['KILOM'],
            ], $db->select(
                'select tr.napcod, n.napname, tr.statcod, tr.kilom
                   from traces tr
                   inner join naplst n on (n.awtcod = tr.awtcod) and (n.napcod = tr.napcod) and (n.isdel = 0)
                  where tr.awtcod = ? and tr.isdel = 0 and tr.statname = ?
                  order by n.napname',
                [$awtcod, $stationName],
            ));

            // Куда продаются билеты ОТ остановки: свои тарифы со
            // STARTSTATCODE остановки (а не 0, как у продажи от станции).
            $result['destinations'] = array_map(fn (array $row) => [
                'name' => (string) $row['STATNAME'],
                'directions' => (int) $row['DIRECTIONS'],
            ], $db->select(
                'select tr_to.statname, count(distinct t.napcode) as directions
                   from tarifs2d t
                   inner join traces tr_from on (tr_from.awtcod = t.awtcode) and (tr_from.napcod = t.napcode)
                        and (tr_from.statcod = t.startstatcode) and (tr_from.isdel = 0)
                   inner join traces tr_to on (tr_to.awtcod = t.awtcode) and (tr_to.napcod = t.napcode)
                        and (tr_to.statcod = t.statcode) and (tr_to.isdel = 0)
                   inner join naplst n on (n.awtcod = t.awtcode) and (n.napcod = t.napcode) and (n.isdel = 0)
                  where t.awtcode = ? and t.prim = 1 and t.startstatcode <> 0
                    and (t.paysoftall > 0 or t.payhardall > 0)
                    and tr_from.statname = ? and tr_to.kilom > tr_from.kilom
                  group by tr_to.statname
                  order by tr_to.statname',
                [$awtcod, $stationName],
            ));

            /*
             * Посадка разрешается в КАЖДОМ расписании остановок
             * (`TRACETIME`), а их у направления бывает по двадцать: у
             * Ливен тариф от Дросково был заведён, посадка разрешена
             * ровно в одном расписании — и ни один рейс этим расписанием
             * не ходил, поэтому продажи не было.
             */
            $boarding = $db->select(
                'select count(*) as total,
                        sum(case when coalesce(tt.set_disabled, 0) = 0 then 1 else 0 end) as allowed,
                        sum(case when tt.time_of_stop is null then 1 else 0 end) as no_stop_time
                   from tracetime tt
                   inner join traces tr on (tr.awtcod = tt.awtcod) and (tr.napcod = tt.napcod)
                        and (tr.statcod = tt.statcod) and (tr.isdel = 0)
                  where tt.awtcod = ? and tr.statname = ?',
                [$awtcod, $stationName],
            );

            $total = (int) ($boarding[0]['TOTAL'] ?? 0);
            $allowed = (int) ($boarding[0]['ALLOWED'] ?? 0);

            $result['boarding'] = [
                'allowed' => $allowed,
                'denied' => max(0, $total - $allowed),
                'no_stop_time' => (int) ($boarding[0]['NO_STOP_TIME'] ?? 0),
            ];

            $result['warnings'] = $this->warnings($result);
            $result['ok'] = $result['destinations'] !== [] && $allowed > 0;
        } catch (Throwable $e) {
            report($e);
            $result['error'] = $e->getMessage();
        }

        return $result;
    }

    /** @return array<int, string> */
    private function warnings(array $state): array
    {
        $warnings = [];

        if ($state['directions'] === []) {
            $warnings[] = 'Остановки с таким названием в базе станции нет — проверьте написание.';
        }

        if ($state['destinations'] === []) {
            $warnings[] = 'От остановки не заведён ни один тариф: станция должна добавить строки'
                .' TARIFS2D с кодом этой остановки в поле STARTSTATCODE.';
        }

        if ($state['boarding']['allowed'] === 0 && $state['directions'] !== []) {
            $warnings[] = 'Посадка на остановке запрещена во всех расписаниях остановок'
                .' (TRACETIME.SET_DISABLED = 1) — рейсы в продажу не попадут.';
        } elseif ($state['boarding']['denied'] > 0) {
            $warnings[] = 'Посадка разрешена не везде: '.$state['boarding']['allowed']
                .' расписаний из '.($state['boarding']['allowed'] + $state['boarding']['denied'])
                .'. Рейсы с остальными расписаниями продаваться не будут.';
        }

        if ($state['boarding']['no_stop_time'] > 0) {
            $warnings[] = 'В '.$state['boarding']['no_stop_time'].' расписаниях не заполнено время'
                .' стоянки (TIME_OF_STOP) — время отправления считаем без неё.';
        }

        return $warnings;
    }

    /** @return array<string, mixed> */
    private function station(int $awtcod): array
    {
        $station = $this->stations->all()[(string) $awtcod] ?? null;

        if ($station === null) {
            throw new \RuntimeException('Станция '.$awtcod.' не найдена в справочнике');
        }

        return $station;
    }

    private function connect(array $station): StationConnection
    {
        return new StationConnection($station, $this->settings->stationConnectTimeout());
    }
}
