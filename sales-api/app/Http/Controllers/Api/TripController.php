<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Buster\GatewayFactory;
use App\Services\Buster\StationRegistry;
use App\Support\VendorCode;
use App\Support\VendorText;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TripController extends Controller
{
    public function __construct(
        private readonly GatewayFactory $gateways,
        private readonly StationRegistry $stations,
    ) {}

    /**
     * Рейсы: GET /api/v1/trips?awtcod=&station=&date=&for_sale=1&from=
     *
     * Без date — режим «ближайшие» (legacy blij=1): рейсы всех дат начиная
     * с сегодняшней, не больше 200.
     *
     * `from` — остановка отправления на трассе рейса (продажа «от остановки
     * до остановки», docs/BOARDING_POINTS.md). Пусто — от самой станции, как
     * было всегда.
     */
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'awtcod' => VendorCode::rules(),
            /*
             * Название остановки — VARCHAR(20) WIN1251 в TRACES.STATNAME,
             * поэтому и предел длины, и проверка кодировки по полю базы:
             * строка длиннее или с непредставимым символом даёт не пустой
             * список, а ошибку Firebird, то есть 500 (см. VendorText).
             */
            'station' => VendorText::rules(VendorText::MAX_STATION_NAME),
            // Остановка отправления — то же поле TRACES.STATNAME, те же
            // ограничения, что и у пункта назначения.
            'from' => ['sometimes', ...VendorText::rules(VendorText::MAX_STATION_NAME, false)],
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

        $fromStation = trim((string) ($data['from'] ?? ''));

        $trips = collect($gateway->routes(
            (int) $data['awtcod'],
            $data['station'],
            $date,
            (bool) ($data['for_sale'] ?? true),
            $fromStation,
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
            /*
             * Отправление С ОСТАНОВКИ полным моментом времени: у продажи от
             * остановки `time_start` — это по-прежнему отправление РЕЙСА (от
             * Орла), а садится пассажир на 2 часа позже и, у вечерних рейсов,
             * уже следующими сутками.
             */
            'departure_at' => $r['DEPARTURE_AT'],
            'from_station' => $fromStation !== '' ? $fromStation : null,
            /*
             * Время в пути в минутах. Прибытие считается ТОЛЬКО по нему:
             * `time_end` — время суток, и за сутками пути оно уже ничего
             * не говорит (11:15 + 25:35 = 12:50). null — у рейса нет
             * расписания остановок, прибытие неизвестно.
             */
            'time_way_minutes' => isset($r['TIME_WAY_MINUTES']) ? (int) $r['TIME_WAY_MINUTES'] : null,
            'price_full' => (float) $r['PAYHARDALL'],
            'price_child' => (float) $r['PAYHARDCHD'],
            'price_baggage' => (float) $r['PAYHARDBAG'],
            'price_insurance' => (float) $r['DOBRSTR'],
            'free_places' => (int) $r['COL_FREE_PL'],
            /*
             * Гарантированная нижняя граница свободных мест («свободно не
             * менее N»): счётчик рейса не знает о билетах, проданных от
             * остановок, и для посадки на остановке завышает. Точное число
             * даёт выбор места (KS_ALL_PLACES) — см. BusterGateway::routes().
             */
            'free_places_min' => max(0, (int) $r['FREE_PL_MIN']),
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

    /**
     * Места рейса: GET /api/v1/trips/places?awtcod=&date=&napcod=&raicecod=&station=
     *
     * `station` — пункт назначения покупателя. Необязателен (прежние вызовы
     * работают как раньше), но с ним станция учитывает участок пути: место,
     * занятое только до середины трассы, для билета до этой середины
     * свободно. Это же число мест — точный ответ на «свободно не менее N»
     * из выдачи рейсов.
     */
    public function places(Request $request): JsonResponse
    {
        $data = $request->validate([
            'awtcod' => VendorCode::rules(),
            'date' => ['required', 'date_format:Y-m-d'],
            'napcod' => VendorCode::rules(),
            'raicecod' => VendorCode::rules(),
            'station' => ['sometimes', ...VendorText::rules(VendorText::MAX_STATION_NAME, false)],
        ]);

        $gateway = $this->gateways->forStation($data['awtcod'], $request->attributes->get('agent'));

        $rows = $gateway->places(
            (int) $data['awtcod'],
            $data['date'],
            (int) $data['napcod'],
            (int) $data['raicecod'],
            trim((string) ($data['station'] ?? '')),
        );

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
            'awtcod' => VendorCode::rules(),
            'date' => ['required', 'date_format:Y-m-d'],
            'napcod' => VendorCode::rules(),
            'raicecod' => VendorCode::rules(),
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
