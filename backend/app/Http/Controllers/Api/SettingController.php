<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\SettingService;
use Illuminate\Http\JsonResponse;

/**
 * Настройки сайта для фронта: включена ли продажа и возврат билетов.
 * Сайт по ним показывает объявление вместо формы, но решает всё равно
 * сервер — проверка стоит и в оформлении заказа, и в возврате.
 */
class SettingController extends Controller
{
    public function __construct(private readonly SettingService $settings) {}

    public function index(): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $this->settings->publicSettings()]);
    }
}
