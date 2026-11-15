<?php

namespace App\Reports;

use App\Services\StationRegistry;
use Illuminate\Support\Facades\DB;

/**
 * Отчёт «Возвраты сайта» — история возвратов по данным нового сайта
 * (Postgres `site`: refunds + orders). Замена legacy `ais/in_back.php`,
 * где это был отдельный скрипт со своим SQL по Firebird.
 *
 * Сверх legacy:
 *  - три вида возврата в одном списке (билет, багаж, возврат удержания) —
 *    в legacy возврат был только билетом целиком;
 *  - видно удержание по каждой строке и возвращено ли оно покупателю;
 *  - подсветка строк, где деньги ещё не ушли (банк отказал/ждёт);
 *  - ссылки на печать билета и на карточку возврата в АИС.
 */
class SiteRefundsReport implements Report
{
    /** Границы суток — по стене Москвы, как в отчёте продаж. */
    private const TZ = 'Europe/Moscow';

    private const KINDS = [
        'ticket' => 'Билет',
        'baggage' => 'Багаж',
        'penalty' => 'Возврат удержания',
    ];

    private const COLS_DAILY = [
        ['key' => 'date', 'title' => 'Дата', 'type' => 'date'],
        ['key' => 'cnt', 'title' => 'Возвратов, шт.', 'type' => 'number'],
        ['key' => 'cnt_ticket', 'title' => 'Билетов', 'type' => 'number'],
        ['key' => 'cnt_baggage', 'title' => 'Багажа', 'type' => 'number'],
        ['key' => 'cnt_penalty', 'title' => 'Удержаний', 'type' => 'number'],
        ['key' => 'amount', 'title' => 'Возвращено, руб.', 'type' => 'money'],
        ['key' => 'withheld', 'title' => 'Удержано, руб.', 'type' => 'money'],
        ['key' => 'pending', 'title' => 'Деньги не ушли, руб.', 'type' => 'money'],
    ];

    private const COLS_JOURNAL = [
        ['key' => 'date', 'title' => 'Когда', 'type' => 'date'],
        ['key' => 'time', 'title' => 'Время', 'type' => 'time'],
        ['key' => 'kind', 'title' => 'Что вернули', 'type' => 'string'],
        ['key' => 'order', 'title' => 'Заказ', 'type' => 'link'],
        ['key' => 'ticket', 'title' => 'Бланк', 'type' => 'link'],
        ['key' => 'place', 'title' => 'Место', 'type' => 'number'],
        ['key' => 'from', 'title' => 'От', 'type' => 'string'],
        ['key' => 'to', 'title' => 'До', 'type' => 'string'],
        ['key' => 'date_start', 'title' => 'Дата поездки', 'type' => 'date'],
        ['key' => 'percent', 'title' => 'Удержание, %', 'type' => 'number'],
        ['key' => 'price', 'title' => 'Стоимость', 'type' => 'money'],
        ['key' => 'amount', 'title' => 'Возвращено', 'type' => 'money'],
        ['key' => 'withheld', 'title' => 'Удержано', 'type' => 'money'],
        ['key' => 'penalty_back', 'title' => 'Удержание возвращено', 'type' => 'string'],
        ['key' => 'status', 'title' => 'Состояние', 'type' => 'string'],
        ['key' => 'money_back_at', 'title' => 'Деньги вернулись', 'type' => 'date'],
        ['key' => 'fp', 'title' => 'ФП чека', 'type' => 'link'],
        ['key' => 'email', 'title' => 'Почта', 'type' => 'string'],
        ['key' => 'phone', 'title' => 'Телефон', 'type' => 'string'],
    ];

    public function __construct(private readonly StationRegistry $stations) {}

    public function meta(): array
    {
        return [
            'title' => 'Возвраты сайта',
            'group' => 'Сайт Автовокзал57.рф',
            'filters' => [
                // Смысл дат: по возвратам — когда вернули; по поездкам —
                // дата рейса (так ищут «что вернули на завтрашний рейс»).
                ['name' => 'mode', 'type' => 'select', 'label' => 'Период',
                    'options' => [
                        ['value' => 'refunds', 'label' => 'По дате возврата'],
                        ['value' => 'trips', 'label' => 'По дате поездки'],
                    ],
                    'default' => 'refunds'],
                ['name' => 'date1', 'type' => 'date', 'label' => 'Дата, с',
                    'default' => date('Y-m-d', strtotime('-7 day'))],
                ['name' => 'date2', 'type' => 'date', 'label' => 'Дата, по',
                    'default' => date('Y-m-d')],
                ['name' => 'from', 'type' => 'select', 'label' => 'Станция отправления',
                    'options' => $this->stations->selectOptions(), 'default' => 'all'],
                ['name' => 'kind', 'type' => 'select', 'label' => 'Что вернули',
                    'options' => [
                        ['value' => 'all', 'label' => 'Всё'],
                        ['value' => 'ticket', 'label' => 'Билеты'],
                        ['value' => 'baggage', 'label' => 'Багаж'],
                        ['value' => 'penalty', 'label' => 'Возвраты удержаний'],
                    ],
                    'default' => 'all'],
                ['name' => 'problems', 'type' => 'select', 'label' => 'Показывать',
                    'options' => [
                        ['value' => 'all', 'label' => 'Все возвраты'],
                        ['value' => 'pending', 'label' => 'Только где деньги не ушли'],
                    ],
                    'default' => 'all'],
                ['name' => 'search', 'type' => 'text',
                    'label' => 'Поиск: почта, телефон, номер бланка или заказа (даты игнорируются)',
                    'default' => ''],
            ],
        ];
    }

    public function run(array $filters): array
    {
        $date1 = ($filters['date1'] ?? '') ?: date('Y-m-d', strtotime('-7 day'));
        $date2 = ($filters['date2'] ?? '') ?: date('Y-m-d');
        $awtcod = ($filters['from'] ?? 'all') === 'all' ? null : (int) $filters['from'];
        $kind = $filters['kind'] ?? 'all';
        $onlyPending = ($filters['problems'] ?? 'all') === 'pending';
        $search = trim((string) ($filters['search'] ?? ''));
        $byTrips = ($filters['mode'] ?? 'refunds') === 'trips';

        $rows = $this->fetch($date1, $date2, $awtcod, $kind, $onlyPending, $search, $byTrips);

        return [
            'filters' => $filters,
            'errors' => '',
            'summary' => $this->summary($rows),
            'sections' => [
                [
                    'id' => 'daily',
                    'title' => $byTrips ? 'Сводный по датам поездки' : 'Сводный по датам возврата',
                    'collapsed' => false,
                    'columns' => self::COLS_DAILY,
                    'rows' => $this->daily($rows, $byTrips),
                    'totals' => $this->dailyTotals($rows) + ['date' => 'ИТОГО:'],
                ],
                [
                    'id' => 'journal',
                    'title' => 'Журнал возвратов',
                    'collapsed' => false,
                    'columns' => self::COLS_JOURNAL,
                    'rows' => array_map(fn (array $r) => $this->journalRow($r), $rows),
                    'totals' => null,
                ],
            ],
        ];
    }

    /**
     * Строки возвратов с данными заказа. Поиск (почта, телефон, бланк,
     * заказ) игнорирует даты — ищут обычно «этого человека», не период.
     *
     * @return array<int, array<string, mixed>>
     */
    private function fetch(
        string $date1,
        string $date2,
        ?int $awtcod,
        string $kind,
        bool $onlyPending,
        string $search,
        bool $byTrips,
    ): array {
        $query = DB::connection('site')->table('refunds as r')
            ->join('orders as o', 'o.id', '=', 'r.order_id')
            ->leftJoin('refunds as p', 'p.parent_refund_id', '=', 'r.id')
            ->select([
                'r.id', 'r.kind', 'r.serial', 'r.number', 'r.place', 'r.penalty_percent',
                'r.ticket_price', 'r.baggage_price', 'r.amount', 'r.status',
                'r.money_refunded_at', 'r.fiscal_sign', 'r.created_at',
                'o.id as order_id', 'o.from_name', 'o.to_name', 'o.date_start',
                'o.email', 'o.phone', 'o.awtcod',
                DB::raw('p.id is not null as penalty_returned'),
            ]);

        if ($search !== '') {
            $like = '%'.mb_strtolower($search).'%';
            $digits = preg_replace('/\D+/', '', $search);

            $query->where(function ($q) use ($like, $search, $digits) {
                $q->whereRaw('lower(o.email) like ?', [$like])
                    ->orWhere('r.number', 'like', '%'.$search.'%')
                    ->orWhere('r.serial', 'like', '%'.$search.'%')
                    ->orWhere('o.id', 'like', '%'.$search.'%');

                if ($digits !== '') {
                    $q->orWhereRaw("regexp_replace(coalesce(o.phone, ''), '\\D', '', 'g') like ?", ['%'.$digits.'%']);
                }
            });
        } else {
            if ($byTrips) {
                $query->whereBetween('o.date_start', [$date1, $date2]);
            } else {
                $query->where('r.created_at', '>=', $date1.' 00:00:00 '.self::TZ)
                    ->where('r.created_at', '<', date('Y-m-d', strtotime($date2.' +1 day')).' 00:00:00 '.self::TZ);
            }
        }

        if ($awtcod !== null) {
            $query->where('o.awtcod', $awtcod);
        }

        if ($kind !== 'all') {
            $query->where('r.kind', $kind);
        }

        if ($onlyPending) {
            $query->whereNull('r.money_refunded_at');
        }

        $query->orderByDesc('r.created_at')->limit(5000);

        return array_map(fn ($row) => (array) $row, $query->get()->all());
    }

    /** @param  array<int, array<string, mixed>>  $rows */
    private function summary(array $rows): array
    {
        $totals = $this->dailyTotals($rows);

        return [
            ['label' => 'Возвратов, шт.', 'value' => $totals['cnt']],
            ['label' => 'Возвращено покупателям, руб.', 'value' => $totals['amount']],
            ['label' => 'Удержано, руб.', 'value' => $totals['withheld']],
            ['label' => 'Деньги ещё не ушли, руб.', 'value' => $totals['pending']],
        ];
    }

    /**
     * Сводка по датам.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, array<string, mixed>>
     */
    private function daily(array $rows, bool $byTrips): array
    {
        $byDate = [];

        foreach ($rows as $r) {
            $date = $byTrips
                ? substr((string) $r['date_start'], 0, 10)
                : substr((string) $r['created_at'], 0, 10);

            $byDate[$date] ??= [
                'date' => $date, 'cnt' => 0, 'cnt_ticket' => 0, 'cnt_baggage' => 0,
                'cnt_penalty' => 0, 'amount' => 0.0, 'withheld' => 0.0, 'pending' => 0.0,
            ];

            $byDate[$date]['cnt']++;
            $byDate[$date]['cnt_'.$r['kind']] = ($byDate[$date]['cnt_'.$r['kind']] ?? 0) + 1;
            $byDate[$date]['amount'] += (float) $r['amount'];
            $byDate[$date]['withheld'] += $this->withheld($r);
            $byDate[$date]['pending'] += $r['money_refunded_at'] ? 0 : (float) $r['amount'];
        }

        krsort($byDate);

        return array_map(fn (array $d) => $d + ['_class' => $d['pending'] > 0 ? 'row-yellow' : ''], array_values($byDate));
    }

    /** @param  array<int, array<string, mixed>>  $rows */
    private function dailyTotals(array $rows): array
    {
        $totals = ['cnt' => 0, 'cnt_ticket' => 0, 'cnt_baggage' => 0, 'cnt_penalty' => 0,
            'amount' => 0.0, 'withheld' => 0.0, 'pending' => 0.0];

        foreach ($rows as $r) {
            $totals['cnt']++;
            $totals['cnt_'.$r['kind']]++;
            $totals['amount'] += (float) $r['amount'];
            $totals['withheld'] += $this->withheld($r);
            $totals['pending'] += $r['money_refunded_at'] ? 0 : (float) $r['amount'];
        }

        return array_map(fn ($v) => is_float($v) ? round($v, 2) : $v, $totals);
    }

    /** @param  array<string, mixed>  $r */
    private function journalRow(array $r): array
    {
        $site = rtrim((string) config('site.frontend_url'), '/');
        $ais = '/back-ticket?order_id='.$r['order_id'].'&serial='.$r['serial'].'&number='.$r['number'];
        $withheld = $this->withheld($r);

        return [
            'date' => substr((string) $r['created_at'], 0, 10),
            'time' => substr((string) $r['created_at'], 11, 5),
            'kind' => self::KINDS[$r['kind']] ?? $r['kind'],
            'order' => ['text' => $r['order_id'], 'url' => $ais],
            'ticket' => ['text' => trim($r['serial'].' '.$r['number']), 'url' => $ais],
            'place' => $r['place'],
            'from' => $r['from_name'],
            'to' => $r['to_name'],
            'date_start' => $r['date_start'],
            'percent' => (int) $r['penalty_percent'],
            'price' => round((float) $r['ticket_price'] + (float) $r['baggage_price'], 2),
            'amount' => (float) $r['amount'],
            'withheld' => $withheld,
            'penalty_back' => $r['kind'] === 'penalty'
                ? '—'
                : ($r['penalty_returned'] ? 'да' : ($withheld > 0 ? 'нет' : '—')),
            'status' => $this->statusLabel($r),
            'money_back_at' => $r['money_refunded_at'] ? substr((string) $r['money_refunded_at'], 0, 10) : '',
            'fp' => $r['fiscal_sign']
                ? ['text' => $r['fiscal_sign'],
                    'url' => 'https://receipt.taxcom.ru/v01/show?fp='.$r['fiscal_sign'].'&s='.number_format((float) $r['amount'], 2, '.', '')]
                : ['text' => 'нет', 'url' => ''],
            'email' => $r['email'],
            'phone' => $r['phone'],
            // Подсветка: деньги не ушли — красный, банк отказал — оранжевый.
            '_class' => match (true) {
                $r['status'] === 'bank_failed' => 'row-orange',
                ! $r['money_refunded_at'] => 'row-red',
                default => '',
            },
        ];
    }

    /** @param  array<string, mixed>  $r */
    private function withheld(array $r): float
    {
        if ($r['kind'] === 'penalty') {
            return 0.0;
        }

        return round((float) $r['ticket_price'] + (float) $r['baggage_price'] - (float) $r['amount'], 2);
    }

    /** @param  array<string, mixed>  $r */
    private function statusLabel(array $r): string
    {
        return match ($r['status']) {
            'money_refunded' => 'деньги возвращены',
            'bank_failed' => 'банк отказал — вернёт бухгалтерия',
            default => 'ожидает возврата денег',
        };
    }
}
