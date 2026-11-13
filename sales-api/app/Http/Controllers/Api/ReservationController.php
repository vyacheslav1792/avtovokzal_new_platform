<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Reservation;
use App\Services\Buster\GatewayFactory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReservationController extends Controller
{
    public function __construct(private readonly GatewayFactory $gateways) {}

    /**
     * Зарезервировать места (бронь ~24 мин): POST /api/v1/reservations
     * {awtcod, napcod, raicecod, statcod, date, places[] | seat_count}
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'awtcod' => ['required', 'integer'],
            'napcod' => ['required', 'integer'],
            'raicecod' => ['required', 'integer'],
            'statcod' => ['required', 'integer'],
            'date' => ['required', 'date_format:Y-m-d'],
            'places' => ['sometimes', 'array', 'min:1', 'max:20'],
            'places.*' => ['integer', 'min:1', 'max:200'],
            'seat_count' => ['required_without:places', 'integer', 'min:1', 'max:20'],
        ]);

        $agent = $request->attributes->get('agent');
        $gateway = $this->gateways->forStation($data['awtcod'], $agent);

        $result = $gateway->reserve(
            (int) $data['awtcod'],
            (int) $data['napcod'],
            (int) $data['raicecod'],
            (int) $data['statcod'],
            $data['date'],
            array_map('intval', $data['places'] ?? []),
            (int) ($data['seat_count'] ?? 0),
        );

        $reservation = Reservation::create([
            'agent_id' => $agent['id'],
            'awtcod' => $data['awtcod'],
            'napcod' => $data['napcod'],
            'raicecod' => $data['raicecod'],
            'statcod' => $data['statcod'],
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
