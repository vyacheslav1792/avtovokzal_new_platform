<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\SavePageRequest;
use App\Models\Page;
use App\Services\PageService;
use Illuminate\Http\JsonResponse;

/**
 * Страницы сайта из админки АИС (ais-spa, раздел «Страницы сайта»).
 * В legacy правка текста означала выкладку файла url/<slug>.php на боевой
 * сервер, а <title> — правку switch в index.php.
 *
 * БЕЗ авторизации — как контакты и админский возврат; на прод не открывать
 * (авторизация появится на этапе users).
 */
class AdminPageController extends Controller
{
    public function __construct(private readonly PageService $pages) {}

    public function index(): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $this->pages->adminList()]);
    }

    public function show(Page $page): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $this->pages->adminPage($page)]);
    }

    public function store(SavePageRequest $request): JsonResponse
    {
        $page = Page::create($request->validated());

        return response()->json(['success' => true, 'data' => $this->pages->adminPage($page)], 201);
    }

    public function update(SavePageRequest $request, Page $page): JsonResponse
    {
        $page->update($request->validated());

        return response()->json(['success' => true, 'data' => $this->pages->adminPage($page->refresh())]);
    }

    /**
     * Удаление страницы. Пункты меню, ведущие на неё, остаются — их
     * убирает редактор меню (в legacy ссылку тоже правили отдельно).
     */
    public function destroy(Page $page): JsonResponse
    {
        $page->delete();

        return response()->json(['success' => true]);
    }
}
