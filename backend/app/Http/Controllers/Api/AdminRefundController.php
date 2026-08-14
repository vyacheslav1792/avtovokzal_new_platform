<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AdminRefundService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Админский возврат билета (перенос legacy ais/back_bilet.php).
 * Авторизации пока нет — внутренний контур, как ais-spa; защитный код
 * 123321 из legacy сознательно не переносим (решение пользователя,
 * нормальная авторизация — на этапе users/ЛК). На прод не открывать
 * без авторизации.
 */
class AdminRefundController extends Controller
{
    public function __construct(private readonly AdminRefundService $refunds) {}

    /** Карточка билета: сверка со шлюзом, состояние, варианты процентов. */
    public function inspect(Request $request): JsonResponse
    {
        $data = $request->validate([
            'order_id' => ['required', 'string', 'max:36'],
            'serial' => ['nullable', 'string', 'max:20'],
            'number' => ['nullable', 'string', 'max:20'],
        ]);

        return response()->json(['success' => true, 'data' => $this->refunds->inspect(
            $data['order_id'],
            $data['serial'] ?? null,
            $data['number'] ?? null,
        )]);
    }

    /** Выполнить возврат с выбранным процентом удержаний. */
    public function confirm(Request $request): JsonResponse
    {
        $data = $request->validate([
            'order_id' => ['required', 'string', 'max:36'],
            'serial' => ['required', 'string', 'max:20'],
            'number' => ['required', 'string', 'max:20'],
            'percent' => ['required', 'integer'],
        ]);

        return response()->json(['success' => true, 'data' => $this->refunds->confirm(
            $data['order_id'],
            $data['serial'],
            $data['number'],
            (int) $data['percent'],
        )]);
    }

    /** Повторить зависший возврат денег по банку. */
    public function repair(Request $request): JsonResponse
    {
        $data = $request->validate([
            'order_id' => ['required', 'string', 'max:36'],
            'serial' => ['required', 'string', 'max:20'],
            'number' => ['required', 'string', 'max:20'],
        ]);

        return response()->json(['success' => true, 'data' => $this->refunds->repairMoney(
            $data['order_id'],
            $data['serial'],
            $data['number'],
        )]);
    }
}
