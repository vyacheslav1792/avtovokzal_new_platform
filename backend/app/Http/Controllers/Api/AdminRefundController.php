<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AdminRefundService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Админский возврат билета (перенос legacy ais/back_bilet.php).
 * Защитный код 123321 из legacy сознательно не перенесён (решение
 * пользователя): его заменили авторизация сотрудника АИС и личный код
 * подтверждения — в отличие от одного кода на всех, он у каждого свой
 * и видно, кто вернул билет.
 *
 * Права: `back-ticket.inspect` на поиск билета, `back-ticket.refund` на
 * возврат и `back-ticket.repair` на починку зависшего возврата денег;
 * последние два требуют кода подтверждения (middleware `ais.can`).
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
