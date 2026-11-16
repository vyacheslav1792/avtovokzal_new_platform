<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\FiscalRetryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Непробитые фискальные чеки за период (раздел АИС «Непробитые чеки»).
 *
 * Чек ставится в очередь и пробивается пятью попытками по минуте; если
 * ККТ в это время недоступна, операция остаётся без фискального
 * документа. Этот раздел показывает такие операции и позволяет пробить
 * чеки заново, видя результат по каждой.
 */
class AdminFiscalController extends Controller
{
    public function __construct(private readonly FiscalRetryService $fiscal) {}

    /** Что осталось без чека: просмотр, ничего не меняет. */
    public function pending(Request $request): JsonResponse
    {
        $data = $this->dates($request);

        return response()->json([
            'success' => true,
            'data' => $this->fiscal->pending($data['date_from'], $data['date_to']),
        ]);
    }

    /** Пробить чеки за период (операция с ККТ — право с кодом подтверждения). */
    public function retry(Request $request): JsonResponse
    {
        $data = $this->dates($request);

        return response()->json([
            'success' => true,
            'data' => $this->fiscal->retry($data['date_from'], $data['date_to']),
        ]);
    }

    /** @return array{date_from: string, date_to: string} */
    private function dates(Request $request): array
    {
        return $request->validate([
            'date_from' => ['required', 'date_format:Y-m-d'],
            'date_to' => ['required', 'date_format:Y-m-d', 'after_or_equal:date_from'],
        ], [
            'date_to.after_or_equal' => 'Дата «по» не может быть раньше даты «с».',
        ]);
    }
}
