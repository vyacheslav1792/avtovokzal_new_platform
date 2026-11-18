<?php

use App\Reports\StationSalesReport;
use App\Services\StationConnection;
use App\Services\StationRegistry;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$period = [$argv[1] ?? '2026-06-01', $argv[2] ?? '2026-06-30'];
$settings = config('station_sales.legacy');
$site = new StationConnection(array_replace($settings, ['db_name' => 'site']), 5);
$ais = new StationConnection($settings, 5);
$sum = function ($db, $table, $station, $expression, $where, $bindings) {
    $result = [];
    foreach ($db->select("SELECT $station AS CODE, SUM($expression) AS V FROM $table WHERE $where GROUP BY $station", $bindings) as $row) {
        $result[$row['CODE']] = (int) round((float) $row['V'] * 100);
    }

    return $result;
};
$sold = $sum($site, 'ORDERS', 'FROM_ID', 'PRICE', 'date_order between ? and ? AND STATUS=1', $period);
$trips = $sum($site, 'ORDERS', 'FROM_ID', 'PRICE', 'datestart between ? and ? AND STATUS=1', $period);
$advance = $sum($site, 'ORDERS', 'FROM_ID', 'PRICE', 'datestart > ? and date_order between ? and ? AND STATUS=1', [$period[1], ...$period]);
$table = 'BACKS left join ORDERS on ORDERS.ORDER_ID=BACKS.ORDER_ID';
$where = "backs.DATE_MONEY_BACK between ? and ? AND backs.DATE_MONEY_BACK>'2019-02-15'";
$gross = 'backs.BIL+backs.BAGAJ+backs.STRAH';
$back = $sum($site, $table, 'ORDERS.FROM_ID', 'backs.SUMM_BACK', $where, $period);
$backGross = $sum($site, $table, 'ORDERS.FROM_ID', $gross, $where, $period);
$tripBack = $sum($site, $table, 'ORDERS.FROM_ID', $gross, "ORDERS.DATESTART between ? and ? AND backs.DATE_MONEY_BACK>'2019-02-15'", $period);
$advanceBack = $sum($site, $table, 'ORDERS.FROM_ID', $gross, 'ORDERS.DATESTART > ? AND '.$where, [$period[1], ...$period]);
$net = 'PRICE_PLACE+PRICE_BAG';
$inved = $sum($ais, 'SALE_ALL', 'AWTCOD', $net, 'AWTCODSALE=9984 AND DATESTART BETWEEN ? AND ? AND TYPE_OPER=0 AND TICK_BACK=0', $period);
$agent = 'AWTCODSALE IN (9999,9900) AND ';
$inved2 = $sum($ais, 'SALE_ALL', 'AWTCOD', $net, $agent.'DATESTART BETWEEN ? AND ? AND TYPE_OPER=0 AND TICK_BACK=0', $period);
$sales = $agent.'DATESALE BETWEEN ? AND ? AND TYPE_OPER=0 AND KKM_OK=1';
$returns = $agent.'DATESALE BETWEEN ? AND ? AND TYPE_OPER>0';
$agentSale = $sum($ais, 'SALE_ALL', 'AWTCOD', $net, $sales, $period);
$agentBack = $sum($ais, 'SALE_ALL', 'AWTCOD', $net, $returns, $period);
$agentBackGross = $sum($ais, 'SALE_ALL', 'AWTCOD', 'PRICE_PL_ISX', $returns, $period);
$agentAdvance = $sum($ais, 'SALE_ALL', 'AWTCOD', $net, $sales.' AND DATESTART > ?', [...$period, $period[1]]);
$agentAdvanceBack = $sum($ais, 'SALE_ALL', 'AWTCOD', 'PRICE_PL_ISX+PRICE_BAG_ISX', $returns.' AND DATESTART > ?', [...$period, $period[1]]);
$actual = app(StationSalesReport::class)->run(['date1' => $period[0], 'date2' => $period[1]])['sections'][0]['rows'];
$diffs = [];
$index = 0;
foreach (app(StationRegistry::class)->all() as $code => $station) {
    $r = ['summ' => $sold[$code] ?? 0, 'summ_back2' => $back[$code] ?? 0, 'price_back' => $backGross[$code] ?? 0,
        'ezda' => ($trips[$code] ?? 0) - ($tripBack[$code] ?? 0), 'inved' => $inved[$code] ?? 0, 'inved2' => $inved2[$code] ?? 0,
        'preprod' => $advance[$code] ?? 0, 'prepback' => $advanceBack[$code] ?? 0,
        'agentsale' => $agentSale[$code] ?? 0, 'agentback' => $agentBack[$code] ?? 0, 'agentback2' => $agentBackGross[$code] ?? 0,
        'agentpredsale' => $agentAdvance[$code] ?? 0, 'agentpredback' => $agentAdvanceBack[$code] ?? 0];
    $r['prihod'] = $r['summ'] - $r['summ_back2'];
    $r['krz'] = $r['price_back'] - $r['summ_back2'];
    $r['inved3'] = $r['inved'] + $r['inved2'];
    $r['preprodchist'] = $r['preprod'] - $r['prepback'];
    $r['agentkrz'] = $r['agentback2'] - $r['agentback'];
    $r['agentpredit'] = $r['agentpredsale'] - $r['agentpredback'];
    foreach ($r as $key => $expected) {
        $value = (int) round($actual[$index][$key] * 100);
        if ($value !== $expected) {
            $diffs[] = ['station' => $code, 'column' => $key, 'legacy' => $expected / 100, 'new' => $value / 100];
        }
    }
    $index++;
}
echo json_encode(['period' => $period, 'checks' => 19 * $index, 'differences' => $diffs], JSON_UNESCAPED_UNICODE)."\n";
exit(count($diffs) ? 1 : 0);
