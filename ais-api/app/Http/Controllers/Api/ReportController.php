<?php

namespace App\Http\Controllers\Api;

use App\Models\Employee;
use App\Reports\Report;
use App\Services\ModuleRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class ReportController extends Controller
{
    public function __construct(private readonly ModuleRegistry $modules) {}

    /**
     * Список отчётов для меню SPA — только те, которые сотруднику доступны.
     * Скрывать, а не отказывать при запуске: отчёт, которого в меню нет,
     * вопросов не вызывает, а строка с отказом выглядит поломкой.
     */
    public function index(Request $request): JsonResponse
    {
        /** @var Employee $employee */
        $employee = $request->user();
        $list = [];

        foreach (config('reports') as $slug => $class) {
            if (! $employee->can(ModuleRegistry::reportModule($slug).'.view')) {
                continue;
            }

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
    public function meta(Request $request, string $slug): JsonResponse
    {
        return response()->json([
            'ok' => true,
            'report' => $slug,
            'meta' => $this->report($request, $slug)->meta(),
        ]);
    }

    /** Выполнить отчёт (тело запроса — фильтры). */
    public function run(Request $request, string $slug): JsonResponse
    {
        $result = $this->report($request, $slug)->run($request->all());

        return response()->json(['ok' => true, 'report' => $slug] + $result);
    }

    private function report(Request $request, string $slug): Report
    {
        $class = config('reports')[$slug] ?? null;

        abort_if(! $class, response()->json([
            'ok' => false,
            'error' => 'Отчёт не найден: '.$slug,
        ], 404));

        /** @var Employee $employee */
        $employee = $request->user();

        abort_if(! $employee->can(ModuleRegistry::reportModule($slug).'.view'), response()->json([
            'ok' => false,
            'error' => 'Нет доступа к этому отчёту.',
            'forbidden' => true,
        ], 403));

        return app($class);
    }
}
