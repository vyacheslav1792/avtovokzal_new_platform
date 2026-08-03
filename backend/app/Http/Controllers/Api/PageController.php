<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\PageService;
use Illuminate\Http\JsonResponse;

/**
 * Обычные страницы сайта и меню (перенос legacy avtovokzal57: url/<slug>.php
 * плюс списки ссылок и switch с <title> в index.php).
 */
class PageController extends Controller
{
    public function __construct(private readonly PageService $pages) {}

    /** Страница по адресу: /payways, /faq, … */
    public function show(string $slug): JsonResponse
    {
        $page = $this->pages->publicPage($slug);

        if ($page === null) {
            return response()->json(['success' => false, 'error' => 'Страница не найдена'], 404);
        }

        return response()->json(['success' => true, 'data' => $page]);
    }

    /** Меню шапки и подвала. */
    public function menu(): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $this->pages->menu()]);
    }
}
