<?php

namespace App\Reports;

use App\Services\StationRegistry;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;

/**
 * Отчёт «Продажи сайта» — перенос legacy ais/in.php (сводный по датам +
 * лог покупок). Источник — Postgres нового сайта (connection `site`:
 * orders/tickets/refunds), а не legacy Firebird site.
 *
 * Отличия от legacy (осознанные улучшения):
 *  - агрегаты считает SQL по индексам (created_at, awtcod, email),
 *    а не PHP-циклы по JSON заказов;
 *  - статусы новой модели заказа + подсветка проблемных строк (_class);
 *  - ссылки: печать билета (фронт сайта) и чек на receipt.taxcom.ru.
 * Таблица «в разрезе станций и агентов» сознательно НЕ перенесена
 * (будет отдельным этапом).
 */
class SiteSalesReport implements Report
{
    /** Границы суток отчёта — по стене Москвы (created_at хранится в timestamptz). */
    private const TZ = 'Europe/Moscow';

    /**
     * Подписи статусов заказа. Цвет строки задаётся НЕ статусом, а тем, у
     * кого деньги (`rowClass`): legacy красил «не оплатил» серым, а красным —
     * только случай «оплата не отменена», то есть деньги у нас без билета.
     */
    private const STATUS_LABELS = [
        'pending' => 'Создан, не оплачен',
        'payment_registered' => 'Ожидает оплаты',
        'paid' => 'Оплачен, оформление не завершено',
        'failed' => 'Платёж не состоялся',
        // failed с заполненным paid_at — совсем другое дело, см. orderStatus().
        'cancelled' => 'Отменён',
    ];

    private const COLS_DAILY = [
        ['key' => 'date', 'title' => 'Дата', 'type' => 'date'],
        ['key' => 'cnt', 'title' => 'Куплено, шт.', 'type' => 'number'],
        ['key' => 'amount', 'title' => 'Куплено, сумма', 'type' => 'money'],
        ['key' => 'cnt_back', 'title' => 'Возвраты, шт.', 'type' => 'number'],
        ['key' => 'refunded', 'title' => 'Возвращено на карты', 'type' => 'money'],
        ['key' => 'prihod', 'title' => 'Чистый приход', 'type' => 'money'],
        ['key' => 'price_back', 'title' => 'Стоимость возвращённых', 'type' => 'money'],
        ['key' => 'krz', 'title' => 'КРЗ', 'type' => 'money'],
    ];

    private const COLS_JOURNAL = [
        // Заказ и билет ведут на админский возврат /back-ticket (в legacy обе
        // ссылки шли на back_bilet.php), статус — на печать (как legacy).
        ['key' => 'order', 'title' => 'Заказ', 'type' => 'link'],
        ['key' => 'sber', 'title' => 'ID Сбербанка', 'type' => 'string'],
        ['key' => 'date_order', 'title' => 'Дата заказа', 'type' => 'date'],
        ['key' => 'time_order', 'title' => 'Время', 'type' => 'time'],
        ['key' => 'from', 'title' => 'От', 'type' => 'string'],
        ['key' => 'to', 'title' => 'До', 'type' => 'string'],
        ['key' => 'date_start', 'title' => 'Дата поездки', 'type' => 'date'],
        ['key' => 'time_start', 'title' => 'Время поездки', 'type' => 'time'],
        ['key' => 'route', 'title' => 'Маршрут', 'type' => 'string'],
        ['key' => 'status', 'title' => 'Статус', 'type' => 'link'],
        ['key' => 'ticket', 'title' => 'Билет', 'type' => 'link'],
        ['key' => 'place', 'title' => 'Место', 'type' => 'number'],
        ['key' => 'price', 'title' => 'Цена', 'type' => 'money'],
        ['key' => 'baggage', 'title' => 'Багаж', 'type' => 'string'],
        ['key' => 'passenger', 'title' => 'Пассажир', 'type' => 'string'],
        ['key' => 'fp', 'title' => 'Чек ФП', 'type' => 'link'],
        ['key' => 'refund_at', 'title' => 'Дата возврата', 'type' => 'date'],
        ['key' => 'refund_amount', 'title' => 'Сумма возврата', 'type' => 'money'],
        ['key' => 'refund_fp', 'title' => 'ФП возврата', 'type' => 'link'],
        ['key' => 'money_back_at', 'title' => 'Возврат денег', 'type' => 'date'],
        // Возврат удержания: у него номера ТОГО ЖЕ билета, поэтому в журнале
        // он не отдельная строка, а своя колонка — иначе билет дублировался.
        ['key' => 'penalty_back', 'title' => 'Возврат удержания', 'type' => 'money'],
        ['key' => 'email', 'title' => 'Почта', 'type' => 'string'],
        ['key' => 'phone', 'title' => 'Телефон', 'type' => 'string'],
        ['key' => 'ip', 'title' => 'IP', 'type' => 'string'],
    ];

    public function __construct(private readonly StationRegistry $stations) {}

    public function meta(): array
    {
        return [
            'title' => 'Продажи сайта',
            'group' => 'Сайт Автовокзал57.рф',
            'filters' => [
                // Режим = смысл дат: по продажам — дата заказа (legacy in.php),
                // по поездкам — дата поездки (legacy back.php).
                ['name' => 'mode', 'type' => 'select', 'label' => 'Период и порядок',
                    'options' => [
                        ['value' => 'sales', 'label' => 'По продажам (дата заказа)'],
                        ['value' => 'trips', 'label' => 'По поездкам (дата поездки)'],
                    ],
                    'default' => 'sales'],
                ['name' => 'date1', 'type' => 'date', 'label' => 'Дата, с',
                    'default' => date('Y-m-d', strtotime('-1 day'))],
                ['name' => 'date2', 'type' => 'date', 'label' => 'Дата, по',
                    'default' => date('Y-m-d', strtotime('+1 day'))],
                ['name' => 'from', 'type' => 'select', 'label' => 'Станция отправления',
                    'options' => $this->stations->selectOptions(), 'default' => 'all'],
                ['name' => 'email', 'type' => 'text',
                    'label' => 'E-mail (поиск по всей истории, даты игнорируются)', 'default' => ''],
            ],
        ];
    }

    public function run(array $filters): array
    {
        $date1 = ($filters['date1'] ?? '') ?: date('Y-m-d', strtotime('-1 day'));
        $date2 = ($filters['date2'] ?? '') ?: date('Y-m-d', strtotime('+1 day'));
        $awtcod = ($filters['from'] ?? 'all') === 'all' ? null : (int) $filters['from'];
        $email = mb_strtolower(trim((string) ($filters['email'] ?? '')));
        // По поездкам (legacy back.php): даты — о date_start, сортировка по рейсу.
        $byTrips = ($filters['mode'] ?? 'sales') === 'trips';

        // Сутки Москвы → полуинтервал timestamptz (btree по created_at работает).
        $from = $date1.' 00:00:00 '.self::TZ;
        $to = date('Y-m-d', strtotime($date2.' +1 day')).' 00:00:00 '.self::TZ;

        $daily = $this->dailySection($from, $to, $date1, $date2, $awtcod, $email, $byTrips);
        [$journal, $problems] = $this->journalSection($from, $to, $date1, $date2, $awtcod, $email, $byTrips);

        return [
            'filters' => $filters,
            'errors' => '',
            'summary' => [
                ['label' => 'Куплено билетов, шт.', 'value' => $daily['totals']['cnt']],
                ['label' => 'Куплено, руб.', 'value' => $daily['totals']['amount']],
                ['label' => 'Возвращено на карты, руб.', 'value' => $daily['totals']['refunded']],
                ['label' => 'Чистый приход, руб.', 'value' => $daily['totals']['prihod']],
                ['label' => 'Проблемных строк в журнале', 'value' => $problems],
            ],
            'sections' => [
                ['id' => 'daily',
                    'title' => $byTrips
                        ? 'Сводный по датам поездки'
                        : 'Сводный по датам (дата заказа / дата возврата денег)',
                    'collapsed' => false, 'columns' => self::COLS_DAILY,
                    'rows' => $daily['rows'], 'totals' => $daily['totals'] + ['date' => 'ИТОГО:']],
                ['id' => 'journal', 'title' => 'Журнал покупок', 'collapsed' => false,
                    'columns' => self::COLS_JOURNAL, 'rows' => $journal, 'totals' => null],
            ],
        ];
    }

    /**
     * Сводный по датам. По продажам: покупки — по дате заказа, возвраты — по
     * дате возврата денег (как legacy DATE_MONEY_BACK). По поездкам: и то и
     * другое — по дате поездки заказа (как legacy back.php: datestart/datestart2).
     * КРЗ = стоимость возвращённых − возвращено на карты.
     */
    private function dailySection(
        string $from,
        string $to,
        string $date1,
        string $date2,
        ?int $awtcod,
        string $email,
        bool $byTrips,
    ): array {
        $db = DB::connection('site');
        $tz = self::TZ;

        $buyDate = $byTrips ? 'o.date_start' : "(o.created_at at time zone '$tz')::date";
        $refundDate = $byTrips ? 'o.date_start' : "(r.money_refunded_at at time zone '$tz')::date";

        $ordersWhere = $byTrips
            ? 'o.date_start between ? and ?'
            : 'o.created_at >= ? and o.created_at < ?';
        $bind = $byTrips ? [$date1, $date2] : [$from, $to];
        if ($awtcod !== null) {
            $ordersWhere .= ' and o.awtcod = ?';
            $bind[] = $awtcod;
        }
        if ($email !== '') {
            $ordersWhere .= ' and o.email = ?';
            $bind[] = $email;
        }

        $buys = $db->select(
            "select $buyDate as d,
                    count(*) as cnt,
                    sum(t.price + t.baggage * t.price_bag) as amount
             from tickets t
             join orders o on o.id = t.order_id
             where $ordersWhere
             group by 1",
            $bind,
        );

        $refundsWhere = $byTrips
            ? 'o.date_start between ? and ?'
            : 'r.money_refunded_at >= ? and r.money_refunded_at < ?';

        $refunds = $db->select(
            "select $refundDate as d,
                    count(*) as cnt,
                    sum(r.amount) as refunded,
                    sum(r.ticket_price + r.baggage_price) as gross
             from refunds r
             join orders o on o.id = r.order_id
             where r.money_refunded_at is not null
               and $refundsWhere".
            ($awtcod !== null ? ' and o.awtcod = ?' : '').
            ($email !== '' ? ' and o.email = ?' : '').
            ' group by 1',
            $bind,
        );

        $days = [];
        foreach ($buys as $r) {
            $days[$r->d]['cnt'] = (int) $r->cnt;
            $days[$r->d]['amount'] = (float) $r->amount;
        }
        foreach ($refunds as $r) {
            $days[$r->d]['cnt_back'] = (int) $r->cnt;
            $days[$r->d]['refunded'] = (float) $r->refunded;
            $days[$r->d]['price_back'] = (float) $r->gross;
        }
        // По продажам — свежие сверху; по поездкам — хронология рейсов (back.php).
        $byTrips ? ksort($days) : krsort($days);

        $rows = [];
        $totals = ['cnt' => 0, 'amount' => 0.0, 'cnt_back' => 0, 'refunded' => 0.0,
            'prihod' => 0.0, 'price_back' => 0.0, 'krz' => 0.0];

        foreach ($days as $d => $v) {
            $row = [
                'date' => date('d.m.Y', strtotime($d)),
                'cnt' => $v['cnt'] ?? 0,
                'amount' => round($v['amount'] ?? 0, 2),
                'cnt_back' => $v['cnt_back'] ?? 0,
                'refunded' => round($v['refunded'] ?? 0, 2),
                'prihod' => round(($v['amount'] ?? 0) - ($v['refunded'] ?? 0), 2),
                'price_back' => round($v['price_back'] ?? 0, 2),
                'krz' => round(($v['price_back'] ?? 0) - ($v['refunded'] ?? 0), 2),
            ];
            foreach ($totals as $k => $_) {
                $totals[$k] = round($totals[$k] + $row[$k], 2);
            }
            $rows[] = $row;
        }

        return ['rows' => $rows, 'totals' => $totals];
    }

    /**
     * Журнал: строка на каждый выпущенный билет + строка на пассажира
     * незавершённых заказов. E-mail задан — ищем по всей истории (legacy).
     *
     * @return array{0: array[], 1: int} строки и число проблемных
     */
    private function journalSection(
        string $from,
        string $to,
        string $date1,
        string $date2,
        ?int $awtcod,
        string $email,
        bool $byTrips,
    ): array {
        $db = DB::connection('site');

        if ($email !== '') {
            $where = 'o.email = ?';
            $bind = [$email];
        } elseif ($byTrips) {
            $where = 'o.date_start between ? and ?';
            $bind = [$date1, $date2];
        } else {
            $where = 'o.created_at >= ? and o.created_at < ?';
            $bind = [$from, $to];
        }
        if ($awtcod !== null) {
            $where .= ' and o.awtcod = ?';
            $bind[] = $awtcod;
        }

        $order = $byTrips
            ? 'o.date_start, o.time_start, o.created_at'
            : 'o.created_at desc';

        // ВАЖНО: join только возврата САМОГО билета (kind='ticket'). У возврата
        // удержания номера того же билета, и без фильтра билет попадал в
        // журнал дважды; возвраты багажа идут номерами своих бланков и
        // подтягиваются отдельно ($baggageRefunds).
        $tickets = $db->select(
            "select o.id, o.sber_order_id, o.created_at, o.paid_at, o.from_name, o.to_name, o.trip_name,
                    o.date_start, o.time_start, o.back_code,
                    o.status, o.status_reason, o.fiscal_sign, o.total_price, o.email, o.phone, o.ip,
                    t.serial, t.number, t.place, t.type, t.price, t.baggage, t.price_bag,
                    t.baggage_tickets,
                    t.last_name, t.first_name, t.middle_name,
                    r.id as refund_id, r.amount as refund_amount, r.status as refund_status,
                    r.fiscal_sign as refund_fp, r.created_at as refund_at, r.money_refunded_at
             from orders o
             join tickets t on t.order_id = o.id
             left join refunds r on r.order_id = o.id and r.kind = 'ticket'
                  and r.serial = t.serial and r.number = t.number
             where $where
             order by $order, t.position",
            $bind,
        );

        $baggageRefunds = $this->baggageRefunds($db, $where, $bind);
        $penaltyRefunds = $this->penaltyRefunds($db, $where, $bind);

        $pending = $db->select(
            "select o.id, o.sber_order_id, o.created_at, o.paid_at, o.from_name, o.to_name, o.trip_name,
                    o.date_start, o.time_start, o.back_code,
                    o.status, o.status_reason, o.total_price, o.email, o.phone, o.ip, o.passengers
             from orders o
             where o.status <> 'completed' and $where
             order by $order",
            $bind,
        );

        $rows = [];
        $problems = 0;

        foreach ($tickets as $t) {
            $bagsBack = $this->countBaggageBack($t, $baggageRefunds);
            $penalty = $penaltyRefunds[$t->id.'/'.$t->serial.'/'.$t->number] ?? null;
            [$status, $class] = $this->ticketStatus($t, $bagsBack, $penalty);
            $rows[] = $this->baseRow($t, $byTrips) + [
                // Печать доступна только у завершённых заказов.
                'status' => $t->status === 'completed'
                    ? ['text' => $status, 'url' => config('site.frontend_url').'/print/'.$t->id]
                    : $status,
                '_class' => $class,
                'ticket' => $this->ticketCell($t),
                'place' => (int) $t->place,
                'price' => (float) $t->price,
                'baggage' => $this->baggageCell($t, $bagsBack),
                'passenger' => trim($t->last_name.' '.$t->first_name.' '.($t->middle_name ?? '')),
                'fp' => $this->taxcomLink($t->fiscal_sign, (float) $t->total_price),
                'refund_at' => $t->refund_at ? $this->mskDate($t->refund_at) : '',
                'refund_amount' => $t->refund_id ? (float) $t->refund_amount : '',
                'refund_fp' => $this->taxcomLink($t->refund_fp, (float) ($t->refund_amount ?? 0)),
                'money_back_at' => $t->money_refunded_at ? $this->mskDate($t->money_refunded_at) : '',
                'penalty_back' => $penalty ? (float) $penalty->amount : '',
            ];
            if ($class === 'row-red' || $class === 'row-orange') {
                $problems++;
            }
        }

        foreach ($pending as $o) {
            [$status, $class] = $this->orderStatus($o);
            if ($class === 'row-red' || $class === 'row-orange') {
                $problems += 1;
            }

            foreach (json_decode($o->passengers, true) ?: [] as $p) {
                $rows[] = $this->baseRow($o, $byTrips) + [
                    'status' => $status,
                    '_class' => $class,
                    'ticket' => 'не оформлен',
                    'place' => (int) $p['place'],
                    'price' => (float) ($p['price'] ?? 0),
                    'baggage' => ($p['baggage'] ?? 0) > 0
                        ? $p['baggage'].' × '.number_format((float) ($p['price_bag'] ?? 0), 2, '.', '')
                        : '',
                    'passenger' => trim(($p['last_name'] ?? '').' '.($p['first_name'] ?? '').' '.($p['middle_name'] ?? '')),
                    'fp' => '',
                    'refund_at' => '',
                    'refund_amount' => '',
                    'refund_fp' => '',
                    'money_back_at' => '',
                    'penalty_back' => '',
                ];
            }
        }

        // Незавершённые заказы — на свои места: по продажам — свежие сверху,
        // по поездкам — хронология рейсов (как back.php).
        usort($rows, $byTrips
            ? fn (array $a, array $b) => strcmp($a['_sort'], $b['_sort'])
            : fn (array $a, array $b) => strcmp($b['_sort'], $a['_sort']));
        $rows = array_map(function (array $r) {
            unset($r['_sort']);

            return $r;
        }, $rows);

        return [$rows, $problems];
    }

    /** Общие колонки строки журнала (и для билета, и для пассажира без билета). */
    private function baseRow(object $o, bool $byTrips): array
    {
        return [
            '_sort' => $byTrips
                ? $o->date_start.' '.$o->time_start.'|'.$o->created_at
                : $o->created_at.'|'.$o->id,
            // Полный UUID; ссылка — на страницу админского возврата ais-spa
            // (относительная: отчёт открыт в том же SPA). В legacy ORDER_ID
            // вёл на back_bilet.php (у строк без билета — без serial/number).
            'order' => [
                'text' => $o->id,
                'url' => '/back-ticket?order_id='.$o->id.(
                    isset($o->serial) && $o->serial
                        ? '&serial='.rawurlencode($o->serial).'&number='.rawurlencode($o->number)
                        : ''
                ),
            ],
            'sber' => (string) $o->sber_order_id,
            'date_order' => $this->mskDate($o->created_at),
            'time_order' => $this->mskTime($o->created_at),
            'from' => $o->from_name,
            'to' => $o->to_name,
            'date_start' => date('d.m.Y', strtotime($o->date_start)),
            'time_start' => substr((string) $o->time_start, 0, 5),
            'route' => (string) $o->trip_name,
            'email' => $o->email,
            'phone' => (string) $o->phone,
            'ip' => (string) $o->ip,
        ];
    }

    /**
     * Ячейка «Билет»: серия+номер, ссылка — на страницу админского
     * возврата (перенос legacy back_bilet.php: и просмотр состояния,
     * и возврат с выбором процента, и починка зависших денег).
     */
    private function ticketCell(object $t): array|string
    {
        $text = trim($t->serial.' '.$t->number);

        if (! $t->serial || ! $t->number) {
            return $text;
        }

        return [
            'text' => $text,
            'url' => '/back-ticket?order_id='.$t->id
                .'&serial='.rawurlencode($t->serial).'&number='.rawurlencode($t->number),
        ];
    }

    /** Статус строки билета с учётом возврата и фискализации (логика legacy). */
    /**
     * Состояние и цвет строки билета.
     *
     * Цвет отвечает на вопрос «где деньги покупателя»:
     *  - красный — деньги у нас, а билета (или возврата) нет;
     *  - оранжевый — с деньгами порядок, не хватает фискального чека;
     *  - зелёный/сиреневый — норма: куплено / возвращено;
     *  - серый — денег не было.
     *
     * @param  array<string, mixed>  $bagsBack  возвраты багажа этого билета
     */
    private function ticketStatus(object $t, array $bagsBack, ?object $penalty): array
    {
        if ($t->refund_id) {
            if (! $t->money_refunded_at) {
                return ['Возвращён, возврат платежа не выполнен', 'row-red'];
            }
            // Возврат удержания — отдельная операция с деньгами покупателя,
            // и свои неудачи у неё тоже свои.
            if ($penalty && ! $penalty->money_refunded_at) {
                return ['Возвращён, удержание: деньги не отданы', 'row-red'];
            }
            if (! $t->refund_fp) {
                return ['Возвращён, нет фискального', 'row-orange'];
            }
            if ($penalty && ! $penalty->fiscal_sign) {
                return ['Возвращён, по удержанию нет чека', 'row-orange'];
            }

            return [$penalty ? 'Возвращён, удержание возвращено' : 'Возвращён', 'row-blue'];
        }

        if ($t->status === 'completed') {
            // Возврат ТОЛЬКО багажа билет не отменяет — он продолжает
            // действовать, поэтому строка остаётся «куплено».
            $bag = $bagsBack['count'] > 0 ? ', багаж возвращён' : '';

            if (! $t->fiscal_sign) {
                return ['Куплено, нет фискального'.$bag, 'row-orange'];
            }
            if ($bagsBack['pending'] > 0) {
                return ['Куплено, возврат багажа: деньги не отданы', 'row-red'];
            }
            if ($bagsBack['no_receipt'] > 0) {
                return ['Куплено, по возврату багажа нет чека', 'row-orange'];
            }

            return ['Куплено'.$bag, 'row-green'];
        }

        // Билет оформлен, а заказ не завершён — так быть не должно: билет на
        // станции продан, деньги списаны, но покупатель этого билета не
        // получил (ни печати, ни возврата по коду).
        return ['Билет оформлен, заказ не завершён: '.($t->status_reason ?: $t->status), 'row-red'];
    }

    /**
     * Состояние и цвет заказа БЕЗ билетов.
     *
     * `failed` — это два разных случая: деньги списались, а продажа не
     * прошла (деньги у нас, красный) и платёж просто не состоялся
     * (серый, как `silver` у legacy). Различает их `paid_at`.
     */
    private function orderStatus(object $o): array
    {
        $label = self::STATUS_LABELS[$o->status] ?? $o->status;

        if ($o->status === 'failed' && $o->paid_at) {
            $label = 'Деньги списаны, билеты не оформлены';
        }

        $class = match (true) {
            $o->status === 'paid' => 'row-red',
            $o->status === 'failed' => $o->paid_at ? 'row-red' : 'row-gray',
            $o->status === 'payment_registered' => 'row-yellow',
            $o->status === 'pending', $o->status === 'cancelled' => 'row-gray',
            // Неизвестный статус — повод посмотреть (legacy: default red).
            default => 'row-red',
        };

        if ($o->status_reason) {
            $label .= ': '.$o->status_reason;
        }

        return [$label, $class];
    }

    /**
     * Возвраты багажа по заказам выборки: ключ «order_id/number бланка».
     *
     * Отдельным запросом, а не join'ом: у этих возвратов номера БАГАЖНОГО
     * бланка, с номерами билета они не сходятся, и в журнале их не было
     * видно вовсе.
     *
     * @param  array<int, mixed>  $bind
     * @return array<string, object>
     */
    private function baggageRefunds(ConnectionInterface $db, string $where, array $bind): array
    {
        $rows = $db->select(
            "select r.order_id, r.serial, r.number, r.amount, r.status,
                    r.fiscal_sign, r.created_at, r.money_refunded_at
             from refunds r
             join orders o on o.id = r.order_id
             where r.kind = 'baggage' and $where",
            $bind,
        );

        $map = [];

        foreach ($rows as $r) {
            $map[$r->order_id.'/'.$r->number] = $r;
        }

        return $map;
    }

    /**
     * Возвраты удержания по заказам выборки: ключ «order_id/серия/номер».
     *
     * @param  array<int, mixed>  $bind
     * @return array<string, object>
     */
    private function penaltyRefunds(ConnectionInterface $db, string $where, array $bind): array
    {
        $rows = $db->select(
            "select r.order_id, r.serial, r.number, r.amount, r.status,
                    r.fiscal_sign, r.created_at, r.money_refunded_at
             from refunds r
             join orders o on o.id = r.order_id
             where r.kind = 'penalty' and $where",
            $bind,
        );

        $map = [];

        foreach ($rows as $r) {
            $map[$r->order_id.'/'.$r->serial.'/'.$r->number] = $r;
        }

        return $map;
    }

    /**
     * Сколько багажных квитанций билета возвращено и всё ли с ними в порядке.
     *
     * @param  array<string, object>  $baggageRefunds
     * @return array{count: int, pending: int, no_receipt: int}
     */
    private function countBaggageBack(object $t, array $baggageRefunds): array
    {
        $out = ['count' => 0, 'pending' => 0, 'no_receipt' => 0];

        foreach (json_decode((string) ($t->baggage_tickets ?? '[]'), true) ?: [] as $bag) {
            $refund = $baggageRefunds[$t->id.'/'.($bag['number'] ?? '')] ?? null;

            if (! $refund) {
                continue;
            }

            $out['count']++;

            // Отметка об аннулировании бланка (сумма 0): деньги по ней не
            // проходили и чека быть не должно — это не недочёт.
            if ((float) $refund->amount <= 0) {
                continue;
            }

            $out['pending'] += $refund->money_refunded_at ? 0 : 1;
            $out['no_receipt'] += $refund->fiscal_sign ? 0 : 1;
        }

        return $out;
    }

    /** @param  array{count: int, pending: int, no_receipt: int}  $bagsBack */
    private function baggageCell(object $t, array $bagsBack): string
    {
        if ((int) $t->baggage <= 0) {
            return '';
        }

        $cell = $t->baggage.' × '.number_format((float) $t->price_bag, 2, '.', '');

        if ($bagsBack['count'] > 0) {
            $cell .= ', возвращено '.$bagsBack['count'];
        }

        return $cell;
    }

    /** Ссылка на чек ОФД (как legacy fp_url). */
    private function taxcomLink(?string $fp, float $sum): array|string
    {
        if (! $fp || $sum <= 0) {
            return '';
        }

        return [
            'text' => $fp,
            'url' => 'https://receipt.taxcom.ru/v01/show?fp='.rawurlencode($fp).'&s='.$sum,
        ];
    }

    private function mskDate(string $ts): string
    {
        return (new \DateTimeImmutable($ts))
            ->setTimezone(new \DateTimeZone(self::TZ))->format('d.m.Y');
    }

    private function mskTime(string $ts): string
    {
        return (new \DateTimeImmutable($ts))
            ->setTimezone(new \DateTimeZone(self::TZ))->format('H:i:s');
    }
}
