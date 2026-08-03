<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Buster\GatewayFactory;
use App\Services\Buster\StationRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TripController extends Controller
{
    public function __construct(
        private readonly GatewayFactory $gateways,
        private readonly StationRegistry $stations,
    ) {}

    /**
     * Рейсы: GET /api/v1/trips?awtcod=&station=&date=&for_sale=1
     *
     * Без date — режим «ближайшие» (legacy blij=1): рейсы всех дат начиная
     * с сегодняшней, не больше 200.
     */
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'awtcod' => ['required', 'integer'],
            'station' => ['required', 'string', 'max:255'],
            'date' => ['nullable', 'date_format:Y-m-d'],
            'for_sale' => ['sometimes', 'boolean'],
        ]);

        $date = $data['date'] ?? null;

        if ($date !== null && strtotime($date) < time() - 48 * 3600) {
            return response()->json([
                'success' => false,
                'error' => 'Данные за этот период более недоступны',
                'error_code' => 'date_out_of_range',
            ], 422);
        }

        $gateway = $this->gateways->forStation($data['awtcod'], $request->attributes->get('agent'));

        $trips = collect($gateway->routes(
            (int) $data['awtcod'],
            $data['station'],
            $date,
            (bool) ($data['for_sale'] ?? true),
        ))->map(fn (array $r) => [
            'awtcod' => (int) $data['awtcod'],
            'napcod' => (int) $r['NAPCOD'],
            'raicecod' => (int) $r['RAICECOD'],
            'statcode' => (int) $r['STATCODE'],
            'trip_name' => $r['RAICENAME'],
            'direction_name' => $r['NAPNAME'],
            'carrier' => $r['ATPNAME'],
            'bus' => $r['BUSNAME'],
            'date_start' => $r['DATESTART'],
            'time_start' => $r['TIMESTART'],
            'time_end' => $r['TIME_TO_END'],
            'price_full' => (float) $r['PAYHARDALL'],
            'price_child' => (float) $r['PAYHARDCHD'],
            'price_baggage' => (float) $r['PAYHARDBAG'],
            'price_insurance' => (float) $r['DOBRSTR'],
            'free_places' => (int) $r['COL_FREE_PL'],
            'total_places' => (int) $r['SETPLBUS'],
            'platform' => $r['PLATNOM'],
            'available' => (int) $r['AVAILABLE'] === 1,
            'internet_sale_available' => (int) $r['INTERNET_SALE_AVAILABLE'] === 1,
            'transit' => (int) $r['TRANSIT'] === 1,
            'canceled' => (int) $r['CANCELED'] !== 0,
            'disrupt' => (int) $r['DISRUPT'] !== 0,   // рейс сорван
            'gone' => (int) $r['GONE'] !== 0,         // рейс ушёл
            // Ограничения продажи по дальности: билет нельзя купить ближе
            // (min_kilom) / дальше (max_kilom) названной остановки.
            'min_kilom' => $r['MIN_KILOM'],
            'max_kilom' => $r['MAX_KILOM'],
            'fraht' => (int) $r['FRAHT'],
        ])->values();

        return response()->json([
            'success' => true,
            'awtcod' => (int) $data['awtcod'],
            'awtname' => $this->stations->name($data['awtcod']),
            'data' => $trips,
        ]);
    }

    /** Места рейса: GET /api/v1/trips/places?awtcod=&date=&napcod=&raicecod= */
    public function places(Request $request): JsonResponse
    {
        $data = $request->validate([
            'awtcod' => ['required', 'integer'],
            'date' => ['required', 'date_format:Y-m-d'],
            'napcod' => ['required', 'integer'],
            'raicecod' => ['required', 'integer'],
        ]);

        $gateway = $this->gateways->forStation($data['awtcod'], $request->attributes->get('agent'));

        $rows = $gateway->places((int) $data['awtcod'], $data['date'], (int) $data['napcod'], (int) $data['raicecod']);

        // KS_ALL_PLACES: строка на каждое место. Свободно, если TAKDIR=0
        // или резерв без билета просрочен (>30 мин) — правило legacy buy.php.
        $free = [];
        $taken = [];
        foreach ($rows as $row) {
            $place = (int) $row['N_PLACE'];
            if ($place < 1) {
                continue; // строки багажных билетов (N_PLACE=0)
            }

            $expiredReservation = ($row['N_TICKET'] ?? '') === ''
                && ! empty($row['TIMEMARK'])
                && strtotime($row['TIMEMARK']) + 1800 < time();

            if ((int) ($row['TAKDIR'] ?? 1) === 0 || $expiredReservation) {
                $free[] = $place;
            } else {
                $taken[] = $place;
            }
        }

        sort($free);
        sort($taken);

        return response()->json([
            'success' => true,
            'data' => [
                'free' => $free,
                'taken' => $taken,
            ],
        ]);
    }

    /** Статус рейса: GET /api/v1/trips/status?awtcod=&date=&napcod=&raicecod= */
    public function status(Request $request): JsonResponse
    {
        $data = $request->validate([
            'awtcod' => ['required', 'integer'],
            'date' => ['required', 'date_format:Y-m-d'],
            'napcod' => ['required', 'integer'],
            'raicecod' => ['required', 'integer'],
        ]);

        $gateway = $this->gateways->forStation($data['awtcod'], $request->attributes->get('agent'));

        $trip = $gateway->tripStatus((int) $data['awtcod'], $data['date'], (int) $data['napcod'], (int) $data['raicecod']);

        if (! $trip) {
            return response()->json([
                'success' => false,
                'error' => 'Рейс не найден',
                'error_code' => 'trip_not_found',
            ], 404);
        }

        return response()->json(['success' => true, 'data' => $trip]);
    }
}
