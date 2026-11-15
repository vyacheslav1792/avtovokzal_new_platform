<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\SettingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Настройки сайта из админки АИС (раздел «Настройки»).
 *
 * Права сотрудника АИС: `settings.view` и `settings.edit`. Правка — с кодом
 * подтверждения: выключенная продажа билетов останавливает выручку сайта.
 */
class AdminSettingController extends Controller
{
    public function __construct(private readonly SettingService $settings) {}

    public function index(): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $this->settings->adminSettings()]);
    }

    /** PUT заменяет значения всех настроек — как у меню, блоков и контактов. */
    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'settings' => ['present', 'array'],
            'settings.*.key' => ['required', 'string', 'max:64'],
            'settings.*.value' => ['present'],
        ]);

        return response()->json(['success' => true, 'data' => $this->settings->replace($data['settings'])]);
    }
}
