<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Buster\GatewayFactory;
use App\Services\Buster\StationRegistry;
use App\Services\Schedule\RegularScheduleBuilder;
use App\Services\Schedule\TariffCard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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

    public function show(Request $request, int $awtcod): JsonResponse
    {
        $gateway = $this->gateways->forStation($awtcod, $request->attributes->get('agent'));
        $schedule = $this->builder->build($gateway, $awtcod);

        return response()->json([
            'success' => true,
            'awtcod' => $awtcod,
            'awtname' => $this->stations->name($awtcod),
            'data' => $schedule['routes'],
            'generated_at' => $schedule['generated_at'],
        ]);
    }

    /**
     * Тариф рейса по остановкам (legacy /tarif). Тарифную категорию
     * выбирает сервер: `variant=current|upcoming` — действующий тариф
     * или новый, вступающий в силу с даты смены.
     */
    public function tariff(Request $request, int $awtcod): JsonResponse
    {
        $data = $request->validate([
            'napcod' => ['required', 'integer'],
            'raicecod' => ['required', 'integer'],
            'variant' => ['sometimes', 'in:current,upcoming'],
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
            ),
        ]);
    }
}
