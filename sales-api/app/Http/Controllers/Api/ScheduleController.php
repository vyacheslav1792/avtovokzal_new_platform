<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Buster\GatewayFactory;
use App\Services\Buster\StationRegistry;
use App\Services\Schedule\RegularScheduleBuilder;
use App\Services\Schedule\TariffCard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use App\Support\VendorCode;
use App\Support\VendorText;

/**
 * Регулярное расписание станции (перенос legacy action=raspisanie).
 * Отдаёт данные, а не готовую HTML-таблицу, как это делал legacy.
 */
class ScheduleController extends Controller
{
    public function __construct(
        private readonly StationRegistry $stations,
        private readonly GatewayFactory $gateways,
        private readonly RegularScheduleBuilder $builder,
        private readonly TariffCard $tariffs,
    ) {}

    /**
     * `from` — остановка на трассе: тогда отдаётся расписание рейсов,
     * ПРОХОДЯЩИХ ЧЕРЕЗ НЕЁ, с временем отправления от остановки. Нужно
     * местам без своей базы продаж — закрытым станциям и остановкам вроде
     * «Покровское АС» (docs/BOARDING_POINTS.md).
     */
    public function show(Request $request, int $awtcod): JsonResponse
    {
        $data = $request->validate([
            'from' => ['sometimes', ...VendorText::rules(VendorText::MAX_STATION_NAME, false)],
        ]);

        $fromStation = trim((string) ($data['from'] ?? ''));

        $gateway = $this->gateways->forStation($awtcod, $request->attributes->get('agent'));
        $schedule = $this->builder->build($gateway, $awtcod, $fromStation);

        return response()->json([
            'success' => true,
            'awtcod' => $awtcod,
            'awtname' => $this->stations->name($awtcod),
            'from_station' => $fromStation !== '' ? $fromStation : null,
            'data' => $schedule['routes'],
            'generated_at' => $schedule['generated_at'],
        ]);
    }

    /**
     * Тариф рейса по остановкам (legacy /tarif). Тарифную категорию
     * выбирает сервер: `variant=current|upcoming` — действующий тариф
     * или новый, вступающий в силу с даты смены.
     *
     * `from` — остановка отправления на трассе: тогда отдаются цены ОТ
     * НЕЁ (свои строки TARIFS2D со STARTSTATCODE), а расстояния и время в
     * пути считаются от неё же (docs/BOARDING_POINTS.md). Пусто — тариф
     * от самой станции, как было всегда.
     */
    public function tariff(Request $request, int $awtcod): JsonResponse
    {
        $data = $request->validate([
            'napcod' => VendorCode::rules(),
            'raicecod' => VendorCode::rules(),
            'variant' => ['sometimes', 'in:current,upcoming'],
            // Название уходит в базу станции параметром сравнения
            // (TRACES.STATNAME, WIN1251) — отсюда проверка кодировки.
            'from' => ['sometimes', ...VendorText::rules(VendorText::MAX_STATION_NAME, false)],
        ]);

        $gateway = $this->gateways->forStation($awtcod, $request->attributes->get('agent'));

        return response()->json([
            'success' => true,
            'awtname' => $this->stations->name($awtcod),
            'data' => $this->tariffs->build(
                $gateway,
                $awtcod,
                (int) $data['napcod'],
                (int) $data['raicecod'],
                $data['variant'] ?? null,
                trim((string) ($data['from'] ?? '')),
            ),
        ]);
    }
}
