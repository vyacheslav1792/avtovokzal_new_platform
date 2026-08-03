<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateMenuRequest;
use App\Services\PageService;
use Illuminate\Http\JsonResponse;

/**
 * Меню сайта (шапка и подвал) из админки АИС. В legacy оба списка были
 * захардкожены в вёрстке avtovokzal57/index.php.
 */
class AdminMenuController extends Controller
{
    public function __construct(private readonly PageService $pages) {}

    public function index(): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $this->pages->adminMenu()]);
    }

    public function update(UpdateMenuRequest $request): JsonResponse
    {
        $data = $this->pages->replaceMenu(
            $request->validated('header'),
            $request->validated('footer'),
        );

        return response()->json(['success' => true, 'data' => $data]);
    }
}
