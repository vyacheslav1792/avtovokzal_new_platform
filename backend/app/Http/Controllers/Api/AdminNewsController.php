<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\SaveNewsRequest;
use App\Models\News;
use App\Services\NewsService;
use Illuminate\Http\JsonResponse;

/**
 * Новости сайта из админки АИС (ais-spa, раздел «Новости»). В legacy
 * объявление добавлялось правкой avtovokzal57/vidjets/news.php и выкладкой
 * файла на боевой сервер.
 *
 * Права сотрудника АИС: `news.view`, `news.edit`, `news.delete` (последнее
 * с кодом подтверждения) — см. middleware `ais.can` в routes/api.php.
 */
class AdminNewsController extends Controller
{
    public function __construct(private readonly NewsService $news) {}

    public function index(): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $this->news->adminList()]);
    }

    public function show(News $news): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $this->news->adminItem($news)]);
    }

    public function store(SaveNewsRequest $request): JsonResponse
    {
        $news = News::create($request->validated());

        return response()->json(['success' => true, 'data' => $this->news->adminItem($news)], 201);
    }

    public function update(SaveNewsRequest $request, News $news): JsonResponse
    {
        $news->update($request->validated());

        return response()->json(['success' => true, 'data' => $this->news->adminItem($news->refresh())]);
    }

    /**
     * Удаление новости. Прежние ссылки на неё перестают работать — поэтому
     * устаревшую новость правильнее выключать, а не удалять.
     */
    public function destroy(News $news): JsonResponse
    {
        $news->delete();

        return response()->json(['success' => true]);
    }
}
