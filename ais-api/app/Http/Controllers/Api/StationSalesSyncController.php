<?php

namespace App\Http\Controllers\Api;

use App\Services\StationSalesSync;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StationSalesSyncController
{
    public function status(StationSalesSync $sync): JsonResponse
    {
        return response()->json(['ok' => true] + $sync->status());
    }

    public function start(Request $request, StationSalesSync $sync): JsonResponse
    {
        $data = $request->validate([
            // Отсутствие списка = все; пустой список — ошибка, а не все станции.
            'stations' => ['sometimes', 'required', 'array', 'min:1'],
            'stations.*' => ['required', 'integer', 'distinct'],
            'mode' => ['nullable', 'in:incremental,full'],
            'date1' => ['nullable', 'required_with:date2', 'date_format:Y-m-d'],
            'date2' => ['nullable', 'required_with:date1', 'date_format:Y-m-d'],
        ]);

        return response()->json(['ok' => true] + $sync->start($data['stations'] ?? null, $data['date1'] ?? null, $data['date2'] ?? null, mode: $data['mode'] ?? null), 202);
    }
}
