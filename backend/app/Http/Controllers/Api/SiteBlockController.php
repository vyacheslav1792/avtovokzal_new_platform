<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\SiteBlockService;
use Illuminate\Http\JsonResponse;

/**
 * Блоки сайта («виджеты») для фронта: ключ слота → HTML. Запрашиваются
 * один раз на приложение, как меню.
 */
class SiteBlockController extends Controller
{
    public function __construct(private readonly SiteBlockService $blocks) {}

    public function index(): JsonResponse
    {
        // Приводим к объекту: пустой PHP-массив ушёл бы как `[]`, и фронту
        // пришлось бы отличать «блоков нет» от словаря блоков.
        return response()->json(['success' => true, 'data' => (object) $this->blocks->publicBlocks()]);
    }
}
