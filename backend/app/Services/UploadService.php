<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Файлы страниц сайта (PDF документов, плакаты). В legacy лежали в
 * avtovokzal57/docs и заливались по FTP, а ссылки в тексте страниц вели на
 * /docs/<файл> — эти адреса сохраняем, чтобы старые ссылки и поисковая
 * выдача не сломались.
 *
 * Каталог верхнего уровня («docs») в пути обязателен: имя файла само по
 * себе адресом не является.
 */
class UploadService
{
    /** Что разрешено заливать: документы страниц и картинки к ним. */
    private const ALLOWED_EXTENSIONS = [
        'pdf', 'doc', 'docx', 'xls', 'xlsx', 'rtf', 'txt', 'zip',
        'png', 'jpg', 'jpeg', 'gif', 'webp', 'svg',
    ];

    private const MAX_SIZE_KB = 30720; // 30 МБ: перечень 223-ФЗ бывает под 5 МБ

    public static function maxSizeKb(): int
    {
        return self::MAX_SIZE_KB;
    }

    public static function allowedExtensions(): array
    {
        return self::ALLOWED_EXTENSIONS;
    }

    /**
     * Все загруженные файлы: путь, размер, дата, адрес на сайте.
     *
     * @return array<int, array<string, mixed>>
     */
    public function list(): array
    {
        $disk = Storage::disk('uploads');

        $files = collect($disk->allFiles())
            ->reject(fn (string $path) => str_starts_with(basename($path), '.'))
            ->map(fn (string $path) => [
                'path' => $path,
                'url' => '/'.$path,
                'size' => $disk->size($path),
                'modified_at' => date(DATE_ATOM, $disk->lastModified($path)),
            ])
            ->sortBy('path')
            ->values();

        return $files->all();
    }

    /**
     * Сохранить файл. Имя приводим к латинице (кириллица в адресах ломает
     * ссылки при копировании), при совпадении добавляем числовой суффикс —
     * старый файл не затираем, на него могут ссылаться другие страницы.
     */
    public function store(UploadedFile $file, string $dir = 'docs'): array
    {
        $dir = trim($dir, '/');

        if ($dir === '' || ! preg_match('~^[a-z0-9][a-z0-9\-/]*$~i', $dir) || str_contains($dir, '..')) {
            throw new RuntimeException('Недопустимый каталог для загрузки');
        }

        $extension = strtolower($file->getClientOriginalExtension());
        $name = Str::slug(
            pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME),
            dictionary: ['@' => 'at', '.' => '-'],
        );

        if ($name === '') {
            $name = 'file';
        }

        $disk = Storage::disk('uploads');
        $path = "{$dir}/{$name}.{$extension}";

        for ($i = 1; $disk->exists($path); $i++) {
            $path = "{$dir}/{$name}-{$i}.{$extension}";
        }

        $disk->putFileAs($dir, $file, basename($path));

        return [
            'path' => $path,
            'url' => '/'.$path,
            'size' => $disk->size($path),
            'modified_at' => date(DATE_ATOM, $disk->lastModified($path)),
        ];
    }

    /** Удалить файл. Ссылки в текстах страниц при этом не проверяются. */
    public function delete(string $path): bool
    {
        $path = $this->normalize($path);

        return Storage::disk('uploads')->delete($path);
    }

    /**
     * Абсолютный путь файла для отдачи сайту; null — если файла нет.
     * Путь проверяем сами: «..» и абсолютные пути отсекаются до обращения
     * к диску.
     */
    public function absolutePath(string $path): ?string
    {
        $path = $this->normalize($path);
        $disk = Storage::disk('uploads');

        return $disk->exists($path) ? $disk->path($path) : null;
    }

    /** Путь внутри каталога загрузок: без «..», ведущих слэшей и экзотики. */
    private function normalize(string $path): string
    {
        $path = ltrim(trim($path), '/');

        if ($path === '' || str_contains($path, '..') || ! preg_match('~^[A-Za-z0-9._\-/]+$~', $path)) {
            throw new RuntimeException('Недопустимый путь файла');
        }

        return $path;
    }
}
