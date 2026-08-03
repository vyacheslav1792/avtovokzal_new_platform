<?php

namespace App\Reports;

use App\Services\StationRegistry;
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

    private const STATUS_MAP = [
        'pending' => ['Создан, не оплачен', 'row-gray'],
        'payment_registered' => ['Ожидает оплаты', 'row-yellow'],
        'paid' => ['Оплачен, оформление не завершено', 'row-red'],
        'failed' => ['Ошибка оформления', 'row-red'],
        'cancelled' => ['Отменён', 'row-gray'],
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

        $tickets = $db->select(
            "select o.id, o.sber_order_id, o.created_at, o.from_name, o.to_name, o.trip_name,
                    o.date_start, o.time_start, o.back_code,
                    o.status, o.status_reason, o.fiscal_sign, o.total_price, o.email, o.phone, o.ip,
                    t.serial, t.number, t.place, t.type, t.price, t.baggage, t.price_bag,
                    t.last_name, t.first_name, t.middle_name,
                    r.id as refund_id, r.amount as refund_amount, r.status as refund_status,
                    r.fiscal_sign as refund_fp, r.created_at as refund_at, r.money_refunded_at
             from orders o
             join tickets t on t.order_id = o.id
             left join refunds r on r.order_id = o.id and r.serial = t.serial and r.number = t.number
             where $where
             order by $order, t.position",
            $bind,
        );

        $pending = $db->select(
            "select o.id, o.sber_order_id, o.created_at, o.from_name, o.to_name, o.trip_name,
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
            [$status, $class] = $this->ticketStatus($t);
            $rows[] = $this->baseRow($t, $byTrips) + [
                // Печать доступна только у завершённых заказов.
                'status' => $t->status === 'completed'
                    ? ['text' => $status, 'url' => config('site.frontend_url').'/print/'.$t->id]
                    : $status,
                '_class' => $class,
                'ticket' => $this->ticketCell($t),
                'place' => (int) $t->place,
                'price' => (float) $t->price,
                'baggage' => $t->baggage > 0
                    ? $t->baggage.' × '.number_format((float) $t->price_bag, 2, '.', '')
                    : '',
                'passenger' => trim($t->last_name.' '.$t->first_name.' '.($t->middle_name ?? '')),
                'fp' => $this->taxcomLink($t->fiscal_sign, (float) $t->total_price),
                'refund_at' => $t->refund_at ? $this->mskDate($t->refund_at) : '',
                'refund_amount' => $t->refund_id ? (float) $t->refund_amount : '',
                'refund_fp' => $this->taxcomLink($t->refund_fp, (float) ($t->refund_amount ?? 0)),
                'money_back_at' => $t->money_refunded_at ? $this->mskDate($t->money_refunded_at) : '',
            ];
            if ($class === 'row-red' || $class === 'row-orange') {
                $problems++;
            }
        }

        foreach ($pending as $o) {
            [$status, $class] = self::STATUS_MAP[$o->status] ?? [$o->status, 'row-red'];
            if ($o->status_reason) {
                $status .= ': '.$o->status_reason;
            }
            if ($class === 'row-red') {
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
    private function ticketStatus(object $t): array
    {
        if ($t->refund_id) {
            if (! $t->money_refunded_at) {
                return ['Возвращён, возврат платежа не выполнен', 'row-red'];
            }
            if (! $t->refund_fp) {
                return ['Возвращён, нет фискального', 'row-orange'];
            }

            return ['Возвращён', 'row-blue'];
        }

        if ($t->status === 'completed') {
            return $t->fiscal_sign
                ? ['Куплено', 'row-green']
                : ['Куплено, нет фискального', 'row-orange'];
        }

        // Билет есть, а заказ не завершён — так быть не должно.
        return [self::STATUS_MAP[$t->status][0] ?? $t->status, 'row-red'];
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
