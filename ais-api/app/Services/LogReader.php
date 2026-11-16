<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Чтение журналов приложений для раздела АИС «Журналы».
 *
 * Главное правило: файл читается ТОЛЬКО с конца и порциями. На проде
 * laravel.log у backend под 70 МБ (канал `single`, ротации нет), и любая
 * попытка прочитать его целиком — это десятки мегабайт в память ради
 * пятидесяти последних строк.
 *
 * Записи Laravel многострочные: первая строка начинается с «[дата] env.LEVEL:
 * сообщение», дальше идёт стектрейс. Поэтому разбираем не строки, а записи —
 * иначе стектрейс выглядел бы как пятьдесят отдельных «сообщений без уровня».
 */
class LogReader
{
    /** Начало записи Laravel: [2026-11-16 12:24:10] production.ERROR: текст */
    private const ENTRY_RE = '/^\[(\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:[+-]\d{2}:?\d{2})?)\]\s+([A-Za-z0-9_.\-]+)\.([A-Z]+):\s?(.*)$/';

    /**
     * Источники и их файлы: что вообще можно открыть.
     *
     * @return array<int, array<string, mixed>>
     */
    public function sources(): array
    {
        $result = [];

        foreach ((array) config('app_logs.sources') as $key => $source) {
            $files = [];
            $dir = (string) ($source['path'] ?? '');

            foreach ($this->filesIn($dir) as $path) {
                $files[] = [
                    'name' => basename($path),
                    'size' => filesize($path) ?: 0,
                    'modified_at' => $this->stamp(filemtime($path) ?: null),
                ];
            }

            // Свежий файл первым: обычно нужен именно он.
            usort($files, fn ($a, $b) => strcmp((string) $b['modified_at'], (string) $a['modified_at']));

            $result[] = [
                'key' => $key,
                'title' => $source['title'] ?? $key,
                'hint' => $source['hint'] ?? null,
                // Каталог может быть не смонтирован (локальный контур без
                // части сервисов) — тогда источник показываем пустым, а не
                // роняем страницу.
                'available' => is_dir($dir),
                'files' => $files,
            ];
        }

        return $result;
    }

    /**
     * Записи журнала, новые сверху.
     *
     * @param  array{levels?: array<int, string>, q?: ?string, limit?: ?int, before?: ?int}  $options
     * @return array<string, mixed>
     */
    public function entries(string $source, ?string $file, array $options = []): array
    {
        $path = $this->resolve($source, $file);

        $limit = max(1, min(200, (int) ($options['limit'] ?? config('app_logs.per_page'))));
        // Уровней можно выбрать сколько угодно: разбираются обычно
        // «ошибки и предупреждения вместе», а не один уровень.
        $levels = array_values(array_filter(array_map(
            fn ($l) => strtoupper(trim((string) $l)),
            (array) ($options['levels'] ?? []),
        )));
        $needle = trim((string) ($options['q'] ?? ''));
        $size = filesize($path) ?: 0;

        // `before` — позиция в файле, ДО которой продолжаем читать назад
        // («показать ещё»). Без него читаем от самого конца.
        $end = $options['before'] ?? null;
        $end = $end === null ? $size : max(0, min((int) $end, $size));

        $chunk = (int) config('app_logs.chunk_bytes');
        $maxScan = (int) config('app_logs.max_scan_bytes');

        $entries = [];
        $from = $end;
        $truncated = false;

        while (true) {
            // Сколько ещё можно прочитать: и порцией, и в пределах бюджета
            // запроса. Дошли до начала файла — $from станет 0.
            $budget = $maxScan - ($end - $from);

            if ($budget <= 0) {
                $truncated = $from > 0;
                break;
            }

            $read = (int) min($chunk, $from, $budget);

            if ($read <= 0) {
                break;
            }

            $from -= $read;
            $slice = (string) file_get_contents($path, false, null, $from, $end - $from);
            $base = $from;

            // Начало куска почти всегда попадает в середину строки — её
            // отбрасываем (кроме случая, когда дошли до начала файла).
            if ($from > 0) {
                $cut = strpos($slice, "\n");
                $slice = $cut === false ? '' : substr($slice, $cut + 1);
                $base = $cut === false ? $from + strlen($slice) : $from + $cut + 1;
            }

            $found = $this->filter($this->parse($slice, $base), $levels, $needle);

            if (count($found) >= $limit || $from === 0) {
                $entries = array_slice($found, -$limit);
                break;
            }

            // Записей не хватило — расширяем прочитанный хвост файла.
            $entries = $found;
        }

        /*
         * Докуда продолжать «показать ещё»: НАЧАЛО самой старой отданной
         * записи, а не позиция, до которой дочитали. Иначе записи между
         * ними (прочитанные, но не попавшие в выдачу из-за limit) при
         * следующем запросе были бы пропущены.
         */
        $nextBefore = $entries === [] ? ($from > 0 ? $from : null) : $entries[0]['offset'];

        if ($nextBefore !== null && $nextBefore <= 0) {
            $nextBefore = null;
        }

        $entries = array_reverse($entries);

        return [
            'source' => $source,
            'file' => basename($path),
            'size' => $size,
            'modified_at' => $this->stamp(filemtime($path) ?: null),
            'entries' => array_map(fn (array $e) => $this->present($e), $entries),
            // С этой позицией клиент просит «показать ещё» — записи,
            // которые в журнале стоят раньше отданных.
            'next_before' => $nextBefore,
            'scanned_bytes' => $end - $from,
            // Поиск перебрал предел и до начала файла не дошёл: нашлось не
            // обязательно всё, и об этом надо сказать прямо.
            'truncated' => $truncated,
        ];
    }

    /** Путь файла журнала для скачивания (проверки те же). */
    public function filePath(string $source, ?string $file): string
    {
        return $this->resolve($source, $file);
    }

    /**
     * Разбор текста в записи. Строка, начинающаяся с «[дата] env.LEVEL:», —
     * новая запись; всё остальное дописывается к предыдущей (стектрейс).
     *
     * @return array<int, array<string, mixed>>
     */
    private function parse(string $text, int $base = 0): array
    {
        $entries = [];
        $offset = $base;

        foreach (explode("\n", $text) as $line) {
            $lineOffset = $offset;
            // +1 — отброшенный разделитель строк; смещения нужны, чтобы
            // «показать ещё» продолжало ровно оттуда, где кончилась выдача.
            $offset += strlen($line) + 1;

            if (preg_match(self::ENTRY_RE, $line, $m) === 1) {
                $entries[] = [
                    'offset' => $lineOffset,
                    'time' => $m[1],
                    'env' => $m[2],
                    'level' => strtoupper($m[3]),
                    'message' => $m[4],
                    'details' => '',
                ];

                continue;
            }

            if ($entries === [] || trim($line) === '') {
                // Хвост предыдущей (обрезанной) записи либо пустая строка —
                // в выдачу такое не попадает.
                continue;
            }

            $last = count($entries) - 1;
            $entries[$last]['details'] .= ($entries[$last]['details'] === '' ? '' : "\n").$line;
        }

        return $entries;
    }

    /**
     * @param  array<int, array<string, mixed>>  $entries
     * @param  array<int, string>  $levels  пустой список = любой уровень
     */
    private function filter(array $entries, array $levels, string $needle): array
    {
        if ($levels === [] && $needle === '') {
            return $entries;
        }

        $needle = mb_strtolower($needle);

        return array_values(array_filter($entries, function (array $e) use ($levels, $needle) {
            if ($levels !== [] && ! in_array($e['level'], $levels, true)) {
                return false;
            }

            if ($needle === '') {
                return true;
            }

            // Ищем и по стектрейсу: «в каком файле упало» ищут чаще, чем
            // текст самого сообщения.
            return str_contains(mb_strtolower($e['message'].' '.$e['details']), $needle);
        }));
    }

    private function present(array $entry): array
    {
        $max = (int) config('app_logs.max_entry_chars');

        return [
            'time' => $this->stamp(strtotime($entry['time']) ?: null),
            'level' => $entry['level'],
            'env' => $entry['env'],
            'message' => mb_substr($entry['message'], 0, $max),
            'details' => mb_substr($entry['details'], 0, $max),
            'details_truncated' => mb_strlen($entry['details']) > $max,
        ];
    }

    /**
     * Проверка пути. Имя файла приходит от клиента, поэтому: только
     * basename, только `.log`, и файл обязан лежать ИМЕННО в каталоге
     * источника (сверяем realpath) — иначе параметром можно было бы
     * вытащить любой файл сервера.
     */
    private function resolve(string $source, ?string $file): string
    {
        $config = config("app_logs.sources.$source");

        if (! is_array($config)) {
            throw ValidationException::withMessages(['source' => 'Неизвестный журнал.']);
        }

        $dir = realpath((string) $config['path']);

        if ($dir === false) {
            throw ValidationException::withMessages([
                'source' => 'Каталог журнала недоступен: '.($config['title'] ?? $source).'.',
            ]);
        }

        $files = $this->filesIn($dir);

        if ($files === []) {
            throw ValidationException::withMessages(['file' => 'Журнал пока пуст.']);
        }

        $name = $file === null || trim($file) === '' ? null : basename(trim($file));

        if ($name === null) {
            // По умолчанию — свежий файл.
            usort($files, fn ($a, $b) => (filemtime($b) ?: 0) <=> (filemtime($a) ?: 0));

            return $files[0];
        }

        $path = realpath($dir.'/'.$name);

        if ($path === false || ! in_array($path, $files, true)) {
            throw ValidationException::withMessages(['file' => 'Такого файла журнала нет.']);
        }

        return $path;
    }

    /** @return array<int, string> */
    private function filesIn(string $dir): array
    {
        if (! is_dir($dir)) {
            return [];
        }

        $files = [];

        foreach ((array) glob(rtrim($dir, '/').'/*.log') as $path) {
            if (is_file($path) && is_readable($path)) {
                $files[] = (string) realpath($path);
            }
        }

        return $files;
    }

    private function stamp(?int $timestamp): ?string
    {
        return $timestamp === null
            ? null
            : Carbon::createFromTimestamp($timestamp, config('app.timezone'))->format('d.m.Y H:i:s');
    }
}
