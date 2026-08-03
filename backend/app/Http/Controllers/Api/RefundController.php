<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\RefundService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Возврат билета по коду для возврата (страница /backbycode). */
class RefundController extends Controller
{
    public function __construct(private readonly RefundService $refunds) {}

    /** Расчёт условий возврата по коду с билета. */
    public function preview(Request $request): JsonResponse
    {
        $data = $request->validate(
            ['code' => ['required', 'string', 'min:16', 'max:80']],
            ['code.min' => 'Не корректный код возврата'],
        );

        return response()->json([
            'success' => true,
            'data' => $this->refunds->preview($data['code']),
        ]);
    }

    /** Подтверждение возврата по токену из preview. */
    public function confirm(Request $request): JsonResponse
    {
        $data = $request->validate([
            'confirm_token' => ['required', 'string'],
        ]);

        return response()->json([
            'success' => true,
            'data' => $this->refunds->confirm($data['confirm_token']),
        ]);
    }
}
