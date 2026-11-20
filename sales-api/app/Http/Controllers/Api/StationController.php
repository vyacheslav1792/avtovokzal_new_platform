<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Buster\BusterGateway;
use App\Services\Buster\GatewayFactory;
use App\Services\Buster\StationRegistry;
use App\Support\StationNameSorter;
use App\Support\VendorText;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StationController extends Controller
{
    public function __construct(
        private readonly StationRegistry $stations,
        private readonly GatewayFactory $gateways,
    ) {}

    /** Станции отправления, обслуживаемые платформой. */
    public function index(): JsonResponse
    {
        $list = collect($this->stations->all())
            ->map(fn (array $s, string $awtcod) => [
                'awtcod' => (int) $awtcod,
                'name' => $s['name'],
            ])
            ->values();

        return response()->json(['success' => true, 'data' => $list]);
    }

    /**
     * Пункты назначения, до которых станция продаёт билеты.
     *
     * `from` — остановка на трассе рейса: тогда отдаём пункты, до которых
     * билет продаётся ОТ НЕЁ (свои тарифы `TARIFS2D.STARTSTATCODE`,
     * docs/BOARDING_POINTS.md). Без `from` — как было: пункты, до которых
     * продаёт сама станция.
     *
     * Порядок задаём здесь, а не в запросе: KS_STATIONS отдаёт строки в
     * порядке базы (с учётом регистра и знаков препинания) — см.
     * StationNameSorter.
     */
    public function destinations(Request $request, int $awtcod): JsonResponse
    {
        $data = $request->validate([
            'from' => ['sometimes', ...VendorText::rules(VendorText::MAX_STATION_NAME, false)],
        ]);

        $fromStation = trim((string) ($data['from'] ?? ''));

        $gateway = $this->gateways->forStation($awtcod, $request->attributes->get('agent'));

        if ($fromStation !== '') {
            return response()->json([
                'success' => true,
                'from_station' => $fromStation,
                'data' => $this->fromStop($gateway, $awtcod, $fromStation),
            ]);
        }

        $sorted = StationNameSorter::sort(
            $gateway->destinations($awtcod),
            fn (array $row) => (string) $row['STATION_NAME'],
        );

        $destinations = collect($sorted)
            ->map(fn (array $row) => [
                'name' => $row['STATION_NAME'],
                'pre_sale_days' => (int) $row['PRE_SALES_DAY'],
            ])
            ->values();

        return response()->json(['success' => true, 'data' => $destinations]);
    }

    /**
     * Пункты назначения от остановки.
     *
     * Срок предпродажи вендор отдаёт только для продажи со своей станции
     * (`KS_STATIONS.PRE_SALE_DAYS`), а для остановки такого источника нет:
     * берём срок того же пункта у самой станции — выбор остановки
     * отправления не может открывать продажу дальше, чем её открыла станция.
     *
     * @return array<int, array{name: string, pre_sale_days: int}>
     */
    private function fromStop(BusterGateway $gateway, int $awtcod, string $fromStation): array
    {
        $preSaleDays = [];
        foreach ($gateway->destinations($awtcod) as $row) {
            $preSaleDays[(string) $row['STATION_NAME']] = (int) $row['PRE_SALES_DAY'];
        }

        $default = (int) config('stations.default_pre_sale_days', 10);

        $sorted = StationNameSorter::sort(
            $gateway->destinationsFromStop($awtcod, $fromStation),
            fn (array $row) => (string) $row['STATION_NAME'],
        );

        return collect($sorted)
            ->map(fn (array $row) => [
                'name' => $row['STATION_NAME'],
                'pre_sale_days' => $preSaleDays[(string) $row['STATION_NAME']] ?? $default,
            ])
            ->values()
            ->all();
    }
}
