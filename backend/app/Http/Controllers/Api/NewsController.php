<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\NewsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Новости и объявления сайта для фронта. В legacy раздела не было:
 * объявления жили в avtovokzal57/vidjets/news.php вместе с вёрсткой.
 */
class NewsController extends Controller
{
    public function __construct(private readonly NewsService $news) {}

    /**
     * Список новостей. `featured=1` — только отмеченные «показывать на
     * главной»: этим же запросом живёт блок последних новостей на главной
     * и на стартовом экране приложения.
     */
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
            'featured' => ['nullable', 'boolean'],
        ]);

        return response()->json([
            'success' => true,
            'data' => $this->news->page(
                (int) ($data['page'] ?? 1),
                (int) ($data['per_page'] ?? NewsService::PER_PAGE),
                (bool) ($data['featured'] ?? false),
            ),
        ]);
    }

    /** Новость по адресу: /news/<slug>. */
    public function show(string $slug): JsonResponse
    {
        $item = $this->news->publicItem($slug);

        if ($item === null) {
            return response()->json(['success' => false, 'error' => 'Новость не найдена'], 404);
        }

        return response()->json(['success' => true, 'data' => $item]);
    }
}
