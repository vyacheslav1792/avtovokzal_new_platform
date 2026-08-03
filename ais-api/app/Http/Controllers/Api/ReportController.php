<?php

namespace App\Http\Controllers\Api;

use App\Reports\Report;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class ReportController extends Controller
{
    /** Список отчётов для меню SPA. */
    public function index(): JsonResponse
    {
        $list = [];
        foreach (config('reports') as $slug => $class) {
            $meta = app($class)->meta();
            $list[] = [
                'slug' => $slug,
                'title' => $meta['title'] ?? $slug,
                'group' => $meta['group'] ?? '',
            ];
        }

        return response()->json(['ok' => true, 'reports' => $list]);
    }

    /** Описание фильтров отчёта. */
    public function meta(string $slug): JsonResponse
    {
        return response()->json([
            'ok' => true,
            'report' => $slug,
            'meta' => $this->report($slug)->meta(),
        ]);
    }

    /** Выполнить отчёт (тело запроса — фильтры). */
    public function run(Request $request, string $slug): JsonResponse
    {
        $result = $this->report($slug)->run($request->all());

        return response()->json(['ok' => true, 'report' => $slug] + $result);
    }

    private function report(string $slug): Report
    {
        $class = config('reports')[$slug] ?? null;

        abort_if(! $class, response()->json([
            'ok' => false,
            'error' => 'Отчёт не найден: '.$slug,
        ], 404));

        return app($class);
    }
}
