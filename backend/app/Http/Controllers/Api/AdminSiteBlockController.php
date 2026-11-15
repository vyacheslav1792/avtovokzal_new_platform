<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\SiteBlockService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Блоки сайта («виджеты») из админки АИС. В legacy это были PHP-файлы
 * avtovokzal57/vidjets/*.php, которые правились прямо на боевом сервере, —
 * форк админки так и работал: сотрудник видел исходный код виджета.
 *
 * Права сотрудника АИС: `blocks.view` и `blocks.edit`.
 */
class AdminSiteBlockController extends Controller
{
    public function __construct(private readonly SiteBlockService $blocks) {}

    public function index(): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $this->blocks->adminBlocks()]);
    }

    /** PUT заменяет содержимое всех слотов целиком — как у меню и контактов. */
    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'blocks' => ['present', 'array'],
            'blocks.*.key' => ['required', 'string', 'max:64'],
            'blocks.*.content' => ['present', 'string'],
            'blocks.*.is_active' => ['boolean'],
            'blocks.*.publish_from' => ['nullable', 'date'],
            'blocks.*.publish_to' => ['nullable', 'date'],
        ]);

        return response()->json(['success' => true, 'data' => $this->blocks->replace($data['blocks'])]);
    }
}
