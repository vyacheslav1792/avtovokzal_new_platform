<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\UploadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Файлы страниц сайта из админки АИС: PDF регламентирующих документов,
 * перечень 223-ФЗ, плакаты о мошенничестве. В legacy их клали в
 * avtovokzal57/docs по FTP.
 *
 * БЕЗ авторизации — как остальная админка; на прод не открывать.
 */
class AdminUploadController extends Controller
{
    public function __construct(private readonly UploadService $uploads) {}

    public function index(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => [
                'files' => $this->uploads->list(),
                'max_size_kb' => UploadService::maxSizeKb(),
                'extensions' => UploadService::allowedExtensions(),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'file' => [
                'required', 'file',
                'max:'.UploadService::maxSizeKb(),
                'mimes:'.implode(',', UploadService::allowedExtensions()),
            ],
            // Каталог внутри загрузок; legacy-документы лежали в /docs.
            'dir' => ['nullable', 'string', 'max:100'],
        ], [
            'file.required' => 'Выберите файл',
            'file.max' => 'Файл больше '.round(UploadService::maxSizeKb() / 1024).' МБ',
            'file.mimes' => 'Такой тип файла загружать нельзя',
        ]);

        try {
            $file = $this->uploads->store($request->file('file'), $data['dir'] ?? 'docs');
        } catch (RuntimeException $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], 422);
        }

        return response()->json(['success' => true, 'data' => $file], 201);
    }

    public function destroy(Request $request): JsonResponse
    {
        $data = $request->validate([
            'path' => ['required', 'string', 'max:500'],
        ]);

        try {
            $deleted = $this->uploads->delete($data['path']);
        } catch (RuntimeException $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], 422);
        }

        if (! $deleted) {
            return response()->json(['success' => false, 'error' => 'Файл не найден'], 404);
        }

        return response()->json(['success' => true]);
    }
}
