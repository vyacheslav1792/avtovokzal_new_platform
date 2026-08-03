<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\SalesApiClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Справочные данные для фронта: станции, направления, рейсы, места. */
class CatalogController extends Controller
{
    public function __construct(private readonly SalesApiClient $salesApi) {}

    public function stations(): JsonResponse
    {
        return response()->json($this->salesApi->stations());
    }

    public function destinations(int $awtcod): JsonResponse
    {
        return response()->json($this->salesApi->destinations($awtcod));
    }

    public function trips(Request $request): JsonResponse
    {
        $data = $request->validate([
            'awtcod' => ['required', 'integer'],
            'to' => ['required', 'string', 'max:255'],
            'date' => ['required', 'date_format:Y-m-d'],
        ]);

        return response()->json($this->salesApi->trips(
            (int) $data['awtcod'],
            $data['to'],
            $data['date'],
        ));
    }

    public function places(Request $request): JsonResponse
    {
        $data = $request->validate([
            'awtcod' => ['required', 'integer'],
            'date' => ['required', 'date_format:Y-m-d'],
            'napcod' => ['required', 'integer'],
            'raicecod' => ['required', 'integer'],
        ]);

        return response()->json($this->salesApi->places(
            (int) $data['awtcod'],
            $data['date'],
            (int) $data['napcod'],
            (int) $data['raicecod'],
        ));
    }
}
