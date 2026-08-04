<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\SavePassengerRequest;
use App\Services\SavedPassengerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Пассажиры, сохранённые в профиле: список, добавление, правка, удаление.
 * Форма покупки берёт их отсюда и подставляет в билеты.
 */
class CabinetPassengerController extends Controller
{
    public function __construct(private readonly SavedPassengerService $passengers) {}

    public function index(Request $request): JsonResponse
    {
        $list = $this->passengers->list($request->user())
            ->map(fn ($p) => $this->passengers->present($p))
            ->values();

        return response()->json(['success' => true, 'data' => $list]);
    }

    public function store(SavePassengerRequest $request): JsonResponse
    {
        $passenger = $this->passengers->save($request->user(), $request->validated());

        return response()->json([
            'success' => true,
            'data' => $this->passengers->present($passenger),
        ], 201);
    }

    public function update(SavePassengerRequest $request, int $id): JsonResponse
    {
        $target = $this->passengers->find($request->user(), $id);
        $passenger = $this->passengers->save($request->user(), $request->validated(), $target);

        return response()->json([
            'success' => true,
            'data' => $this->passengers->present($passenger),
        ]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $this->passengers->find($request->user(), $id)->delete();

        return response()->json(['success' => true]);
    }
}
