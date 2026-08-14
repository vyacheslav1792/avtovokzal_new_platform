<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Buster\GatewayFactory;
use App\Services\Buster\StationRegistry;
use App\Support\StationNameSorter;
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
     * Порядок задаём здесь, а не в запросе: KS_STATIONS отдаёт строки в
     * порядке базы (с учётом регистра и знаков препинания) — см.
     * StationNameSorter.
     */
    public function destinations(Request $request, int $awtcod): JsonResponse
    {
        $gateway = $this->gateways->forStation($awtcod, $request->attributes->get('agent'));

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
}
