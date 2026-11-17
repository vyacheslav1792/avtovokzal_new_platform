<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Buster\GatewayFactory;
use App\Support\VendorCode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BlankController extends Controller
{
    public function __construct(
        private readonly GatewayFactory $gateways,
    ) {}

    /**
     * Состояние бланков в журнале станции: GET /api/v1/blanks
     * ?awtcod=5701&date_start=2026-11-18&blanks[0][serial]=…&blanks[0][number]=…
     *
     * Отвечает на вопрос «а что станция думает про этот бланк»: продан,
     * возвращён, кем и когда, по какой цене и с каким процентом удержания.
     * Своей таблицы возвратов для этого НЕ достаточно: записи о возврате у
     * нас может не быть при аннулированном на станции бланке, а бланк мог
     * вернуть и кассир станции — тогда деньги покупателю выданы там, и
     * второй возврат на карту делать нельзя.
     *
     * Только чтение: ничего не аннулирует и ведомость не трогает.
     */
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'awtcod' => VendorCode::rules(),
            // Дата рейса сужает выборку по DATESALE/DATESTART: журнал SALE
            // станции — это миллионы строк за годы.
            'date_start' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'blanks' => ['required', 'array', 'min:1', 'max:50'],
            'blanks.*.serial' => ['required', 'string', 'max:20'],
            'blanks.*.number' => ['required', 'string', 'max:20'],
        ]);

        $gateway = $this->gateways->forStation($data['awtcod'], $request->attributes->get('agent'));

        return response()->json([
            'success' => true,
            'data' => [
                'blanks' => $gateway->blankState($data['blanks'], $data['date_start'] ?? null),
            ],
        ]);
    }
}
