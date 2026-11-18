<?php

namespace App\Reports;

use App\Services\StationRegistry;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** Сводная по станциям из in.php: все 20 колонок и исходные условия SALE_ALL. */
class StationSalesReport implements Report
{
    public const SLUG = 'station-sales';

    private const MONEY = [
        'summ' => 'Куплено в отч. периоде авт57',
        'summ_back2' => 'Возвращено в отчётном периоде авт57',
        'prihod' => 'Чистый приход',
        'price_back' => 'Стоимость возвращённых',
        'krz' => 'КРЗ',
        'ezda' => 'В ведомости по данным сайта',
        'inved' => 'В ведомости по данным базы автовокзала',
        'inved2' => 'Агентские в ведомости',
        'inved3' => 'В ведомости интернет общ.',
        'preprod' => 'Пред. прод.',
        'prepback' => 'Возврат пред. прод.',
        'preprodchist' => 'Пред. прод. чист.',
        'agentsale' => 'Агентские продано',
        'agentback' => 'Агентские возврат',
        'agentback2' => 'Агентские стоимость возвращённых',
        'agentkrz' => 'Агентские КРЗ',
        'agentpredsale' => 'Агент предвар.',
        'agentpredback' => 'Агент возвр. предв.',
        'agentpredit' => 'Итог агент предвар.',
    ];

    public function __construct(private readonly StationRegistry $stations) {}

    public function meta(): array
    {
        return [
            'title' => 'Продажи сайта и агентов по станциям',
            'group' => 'Сайт Автовокзал57.рф',
            'permissions' => ['refresh' => 'Обновление данных станций'],
            'station_sales_sync' => true,
            'filters' => [
                ['name' => 'date1', 'type' => 'date', 'label' => 'Дата, с', 'default' => date('Y-m-01')],
                ['name' => 'date2', 'type' => 'date', 'label' => 'Дата, по', 'default' => date('Y-m-d')],
                ['name' => 'from', 'type' => 'select', 'label' => 'Станция отправления',
                    'options' => $this->stations->selectOptions(), 'default' => 'all'],
            ],
        ];
    }

    public function run(array $filters): array
    {
        $filters = validator($filters + ['date1' => date('Y-m-01'), 'date2' => date('Y-m-d'), 'from' => 'all'], [
            'date1' => ['required', 'date_format:Y-m-d', 'before_or_equal:date2'],
            'date2' => ['required', 'date_format:Y-m-d'],
            'from' => ['required', Rule::in(array_merge(['all'], array_map('strval', array_keys($this->stations->all()))))],
        ])->validate();
        $stations = $this->stations->subset((string) $filters['from']);
        $codes = array_keys($stations);
        [$date1, $date2] = [$filters['date1'], $filters['date2']];
        $from = $date1.' 00:00:00 Europe/Moscow';
        $to = date('Y-m-d', strtotime($date2.' +1 day')).' 00:00:00 Europe/Moscow';

        // На новом сайте все продажи и возвраты читаем из Postgres сайта.
        // total_price включает и страхование импортированных заказов (как PRICE).
        $orders = DB::connection('site')->table('orders')->whereIn('awtcod', $codes)->where('status', 'completed')
            ->where(fn ($q) => $q->whereNull('legacy->status')->orWhere('legacy->status', 1));
        $sold = $this->sum((clone $orders)->where('created_at', '>=', $from)->where('created_at', '<', $to), 'total_price');
        $trips = $this->sum((clone $orders)->whereBetween('date_start', [$date1, $date2]), 'total_price');
        $advance = $this->sum((clone $orders)->where('created_at', '>=', $from)->where('created_at', '<', $to)
            ->where('date_start', '>', $date2), 'total_price');

        $refunds = DB::connection('site')->table('refunds as r')->join('orders as o', 'o.id', '=', 'r.order_id')
            ->whereIn('o.awtcod', $codes)->whereNotNull('r.money_refunded_at')
            ->where('r.money_refunded_at', '>=', '2019-02-16 00:00:00 Europe/Moscow');
        $insurance = DB::connection('site')->getDriverName() === 'pgsql'
            ? "COALESCE(CAST(r.legacy->>'strah' AS NUMERIC), 0)"
            : "COALESCE(json_extract(r.legacy, '$.strah'), 0)";
        $gross = 'r.ticket_price + r.baggage_price + '.$insurance;
        $inPeriod = (clone $refunds)->where('r.money_refunded_at', '>=', $from)->where('r.money_refunded_at', '<', $to);
        $back = $this->sum(clone $inPeriod, 'r.amount', 'o.awtcod');
        $backGross = $this->sum(clone $inPeriod, $gross, 'o.awtcod');
        $tripBack = $this->sum((clone $refunds)->whereBetween('o.date_start', [$date1, $date2]), $gross, 'o.awtcod');
        $advanceBack = $this->sum((clone $inPeriod)->where('o.date_start', '>', $date2), $gross, 'o.awtcod');

        $sale = DB::table('station_sales')->whereIn('awtcod', $codes);
        $netPrice = 'price_place + price_bag';
        $siteVed = $this->sum((clone $sale)->where('awtcodsale', 9984)->whereBetween('datestart', [$date1, $date2])
            ->where('type_oper', 0)->where('tick_back', 0), $netPrice);
        $agents = (clone $sale)->whereIn('awtcodsale', [9999, 9900]);
        $agentVed = $this->sum((clone $agents)->whereBetween('datestart', [$date1, $date2])->where('type_oper', 0)->where('tick_back', 0), $netPrice);
        $agentSold = (clone $agents)->whereBetween('datesale', [$date1, $date2])->where('type_oper', 0)->where('kkm_ok', 1);
        $agentSale = $this->sum(clone $agentSold, $netPrice);
        $agentAdvance = $this->sum((clone $agentSold)->where('datestart', '>', $date2), $netPrice);
        $agentReturned = (clone $agents)->whereBetween('datesale', [$date1, $date2])->where('type_oper', '>', 0);
        $agentBack = $this->sum(clone $agentReturned, $netPrice);
        // Именно PRICE_PL_ISX без PRICE_BAG_ISX, как в исходном AGENTBACK.
        $agentBackGross = $this->sum(clone $agentReturned, 'price_pl_isx');
        $agentAdvanceBack = $this->sum((clone $agentReturned)->where('datestart', '>', $date2), 'price_pl_isx + price_bag_isx');

        $rows = [];
        $totals = array_fill_keys(array_keys(self::MONEY), 0);
        foreach ($stations as $code => $station) {
            $row = [
                'summ' => $sold[$code] ?? 0, 'summ_back2' => $back[$code] ?? 0,
                'price_back' => $backGross[$code] ?? 0,
                'ezda' => ($trips[$code] ?? 0) - ($tripBack[$code] ?? 0),
                'inved' => $siteVed[$code] ?? 0, 'inved2' => $agentVed[$code] ?? 0,
                'preprod' => $advance[$code] ?? 0, 'prepback' => $advanceBack[$code] ?? 0,
                'agentsale' => $agentSale[$code] ?? 0, 'agentback' => $agentBack[$code] ?? 0,
                'agentback2' => $agentBackGross[$code] ?? 0,
                'agentpredsale' => $agentAdvance[$code] ?? 0, 'agentpredback' => $agentAdvanceBack[$code] ?? 0,
            ];
            $row['prihod'] = $row['summ'] - $row['summ_back2'];
            $row['krz'] = $row['price_back'] - $row['summ_back2'];
            $row['inved3'] = $row['inved'] + $row['inved2'];
            $row['preprodchist'] = $row['preprod'] - $row['prepback'];
            $row['agentkrz'] = $row['agentback2'] - $row['agentback'];
            $row['agentpredit'] = $row['agentpredsale'] - $row['agentpredback'];
            foreach ($totals as $key => $_) {
                $totals[$key] += $row[$key];
                $row[$key] /= 100;
            }
            $rows[] = ['station' => $station['name']] + $row;
        }
        $columns = [['key' => 'station', 'title' => 'Станция', 'type' => 'string']];
        foreach (self::MONEY as $key => $title) {
            $columns[] = ['key' => $key, 'title' => $title, 'type' => 'money'];
            $totals[$key] /= 100;
        }

        return [
            'filters' => $filters, 'errors' => '', 'summary' => [],
            'sections' => [[
                'id' => 'stations', 'title' => 'Сводный отчёт по продажам с сайта Автовокзал57.рф и агентами в разрезе станций',
                'collapsed' => false, 'columns' => $columns, 'rows' => $rows, 'totals' => ['station' => 'ИТОГО:'] + $totals,
            ]],
        ];
    }

    /** SQL отдаёт только итоги по станциям. Арифметика отчёта — в копейках. */
    private function sum(Builder $query, string $expression, string $station = 'awtcod'): array
    {
        return $query->selectRaw("$station AS station_code, SUM($expression) AS amount")
            ->groupBy($station)->get()->mapWithKeys(fn ($row) => [$row->station_code => (int) round((float) $row->amount * 100)])->all();
    }
}
