<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Reservation;
use App\Services\Buster\GatewayFactory;
use App\Services\Buster\StationRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use App\Support\VendorCode;
use App\Support\VendorText;

class ReservationController extends Controller
{
    public function __construct(
        private readonly GatewayFactory $gateways,
        private readonly StationRegistry $stations,
    ) {}

    /**
     * Зарезервировать места (бронь ~24 мин): POST /api/v1/reservations
     * {awtcod, napcod, raicecod, statcod, date, places[] | seat_count, from}
     *
     * `from` — остановка отправления на трассе рейса (продажа «от остановки
     * до остановки»): от неё станция считает цену и время отправления.
     * Пусто — от самой станции, как было всегда.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'awtcod' => VendorCode::rules(),
            'napcod' => VendorCode::rules(),
            'raicecod' => VendorCode::rules(),
            'statcod' => VendorCode::rules(),
            'date' => ['required', 'date_format:Y-m-d'],
            'places' => ['sometimes', 'array', 'min:1', 'max:20'],
            'places.*' => ['integer', 'min:1', 'max:200'],
            'seat_count' => ['required_without:places', 'integer', 'min:1', 'max:20'],
            'from' => ['sometimes', ...VendorText::rules(VendorText::MAX_STATION_NAME, false)],
        ]);

        // Продажа по станции может быть выключена в АИС — проверяем до
        // брони: место, занятое под заказ, который не оформить, никому
        // не нужно.
        $this->stations->assertSalesEnabled($data['awtcod']);

        $agent = $request->attributes->get('agent');
        $gateway = $this->gateways->forStation($data['awtcod'], $agent);

        $fromStation = trim((string) ($data['from'] ?? ''));
        $fromStatcod = 0;

        if ($fromStation !== '') {
            // Тариф от остановки и разрешённая посадка — до брони: сама
            // процедура резерва на это не смотрит и зарезервировала бы
            // место по нулевой цене (docs/BOARDING_POINTS.md).
            $fromStatcod = $gateway->boardingStop(
                (int) $data['awtcod'],
                (int) $data['napcod'],
                (int) $data['raicecod'],
                $data['date'],
                (int) $data['statcod'],
                $fromStation,
            );
        }

        $result = $gateway->reserve(
            (int) $data['awtcod'],
            (int) $data['napcod'],
            (int) $data['raicecod'],
            (int) $data['statcod'],
            $data['date'],
            array_map('intval', $data['places'] ?? []),
            (int) ($data['seat_count'] ?? 0),
            $fromStatcod,
        );

        $reservation = Reservation::create([
            'agent_id' => $agent['id'],
            'awtcod' => $data['awtcod'],
            'napcod' => $data['napcod'],
            'raicecod' => $data['raicecod'],
            'statcod' => $data['statcod'],
            'start_statcod' => $fromStatcod,
            'start_station' => $fromStation !== '' ? $fromStation : null,
            'date_start' => $data['date'],
            'places' => $result['places'],
            'id_time_places' => $result['id_time_places'],
            'datetime_start' => $result['raw']['DATETIME_START'] ?? null,
            'datetime_arrival' => $result['raw']['DATETIME_ARRIVAL'] ?? null,
            'status' => 'active',
            'raw_response' => $result['raw'],
        ]);

        return response()->json([
            'success' => true,
            'data' => [
                'reservation_id' => $reservation->id,
                'id_time_places' => $result['id_time_places'],
                'places' => $result['places'],
                'from_station' => $reservation->start_station,
                /*
                 * datetime_start у продажи от остановки — отправление С
                 * ОСТАНОВКИ: процедура считает его по расписанию остановок
                 * (от Дросково 09:15 при рейсе из Орла в 07:00). Именно это
                 * время идёт в билет и письмо покупателю.
                 */
                'datetime_start' => $reservation->datetime_start,
                'datetime_arrival' => $reservation->datetime_arrival,
                'carrier' => $result['raw']['PRN_NAME_ATP'] ?? null,
                'bus_category' => $result['raw']['BUS_KAT'] ?? null,
            ],
        ], 201);
    }

    /**
     * Снять бронь: DELETE /api/v1/reservations/{id}
     *
     * Нужна, когда покупатель отказался от оплаты: иначе места висят
     * занятыми до конца брони (~24 минуты) и их не может купить никто.
     * Снимает только свою бронь — и по агенту запроса, и внутри BUSTER
     * (процедура сверяет AGENT_CODE). Повторный вызов безопасен: уже
     * снятая бронь отвечает успехом, ничего не делая.
     */
    public function destroy(Request $request, string $id): JsonResponse
    {
        $agent = $request->attributes->get('agent');

        $reservation = Reservation::where('id', $id)
            ->where('agent_id', $agent['id'])
            ->firstOrFail();

        if ($reservation->status !== 'active') {
            return response()->json([
                'success' => true,
                'data' => ['reservation_id' => $reservation->id, 'released' => 0, 'status' => $reservation->status],
            ]);
        }

        $released = $this->gateways->forStation($reservation->awtcod, $agent)->releaseReservation(
            (int) $reservation->awtcod,
            (int) $reservation->napcod,
            (int) $reservation->raicecod,
            $reservation->date_start->format('Y-m-d'),
            array_map('intval', $reservation->places ?? []),
        );

        $reservation->update(['status' => 'cancelled']);

        return response()->json([
            'success' => true,
            'data' => ['reservation_id' => $reservation->id, 'released' => $released, 'status' => 'cancelled'],
        ]);
    }
}
