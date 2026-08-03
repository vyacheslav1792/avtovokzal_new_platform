<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\UploadService;
use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Отдача файлов страниц сайта (PDF документов, плакаты). Сайт проксирует
 * сюда прежние legacy-адреса /docs/<файл>, поэтому старые ссылки и
 * поисковая выдача продолжают работать.
 */
class FileController extends Controller
{
    public function __construct(private readonly UploadService $uploads) {}

    public function show(string $path): Response
    {
        try {
            $absolute = $this->uploads->absolutePath($path);
        } catch (RuntimeException) {
            $absolute = null;
        }

        if ($absolute === null) {
            return response('Файл не найден', 404);
        }

        // Документы меняются редко — разрешаем кэшировать на сутки.
        return (new BinaryFileResponse($absolute))
            ->setPublic()
            ->setMaxAge(86400);
    }
}
