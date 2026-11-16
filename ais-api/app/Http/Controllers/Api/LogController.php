<?php

namespace App\Http\Controllers\Api;

use App\Services\LogReader;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Раздел АИС «Журналы»: что приложения пишут в storage/logs.
 *
 * До него логи смотрели только через ssh и `docker exec … tail`, то есть
 * на вопрос «почему страница отдала Server Error» мог ответить лишь тот,
 * у кого есть доступ к серверу.
 *
 * Права: `logs.view` — смотреть, `logs.download` — скачать файл целиком
 * (в журналах оседают адреса покупателей и номера заказов, поэтому выгрузка
 * отдельным правом).
 */
class LogController extends Controller
{
    public function __construct(private readonly LogReader $logs) {}

    /** Какие журналы есть и какие в них файлы. */
    public function index(): JsonResponse
    {
        return response()->json(['ok' => true, 'sources' => $this->logs->sources()]);
    }

    /** Записи журнала, новые сверху. */
    public function entries(Request $request, string $source): JsonResponse
    {
        $data = $request->validate([
            'file' => ['nullable', 'string', 'max:255'],
            // Уровни — списком через запятую: «ERROR,WARNING». Один уровень
            // сам по себе почти не нужен, обычно смотрят ошибки вместе с
            // предупреждениями.
            'levels' => ['nullable', 'string', 'max:200'],
            'q' => ['nullable', 'string', 'max:255'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:200'],
            // Позиция в файле, до которой читать назад («показать ещё»).
            'before' => ['nullable', 'integer', 'min:0'],
        ]);

        return response()->json(['ok' => true] + $this->logs->entries(
            $source,
            $data['file'] ?? null,
            [
                'levels' => array_filter(explode(',', (string) ($data['levels'] ?? ''))),
                'q' => $data['q'] ?? null,
                'limit' => $data['limit'] ?? null,
                'before' => $data['before'] ?? null,
            ],
        ));
    }

    /**
     * Скачать файл журнала. Отдаём потоком: laravel.log у backend на проде
     * под 70 МБ, и читать его в память ради выгрузки нельзя.
     */
    public function download(Request $request, string $source): StreamedResponse
    {
        $data = $request->validate([
            'file' => ['nullable', 'string', 'max:255'],
        ]);

        $path = $this->logs->filePath($source, $data['file'] ?? null);

        return response()->streamDownload(function () use ($path) {
            $handle = fopen($path, 'rb');

            while ($handle !== false && ! feof($handle)) {
                echo fread($handle, 1024 * 256);
                flush();
            }

            if ($handle !== false) {
                fclose($handle);
            }
        }, $source.'-'.basename($path), ['Content-Type' => 'text/plain; charset=UTF-8']);
    }
}
