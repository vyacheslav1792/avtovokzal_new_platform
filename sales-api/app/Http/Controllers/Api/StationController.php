<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Buster\GatewayFactory;
use App\Services\Buster\StationRegistry;
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

    /** Пункты назначения, до которых станция продаёт билеты. */
    public function destinations(Request $request, int $awtcod): JsonResponse
    {
        $gateway = $this->gateways->forStation($awtcod, $request->attributes->get('agent'));

        $destinations = collect($gateway->destinations($awtcod))
            ->map(fn (array $row) => [
                'name' => $row['STATION_NAME'],
                'pre_sale_days' => (int) $row['PRE_SALES_DAY'],
            ])
            ->values();

        return response()->json(['success' => true, 'data' => $destinations]);
    }
}
