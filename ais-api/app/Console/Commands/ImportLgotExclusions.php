<?php

namespace App\Console\Commands;

use App\Models\LgotExcludedTicket;
use App\Services\LgotExclusions;
use App\Support\LgotKind;
use Illuminate\Console\Command;

/**
 * Перенос списков исключений отчёта «Нулевые билеты» из legacy.
 *
 * В legacy они лежали ФАЙЛАМИ рядом с кодом АИС, своими у каждой из трёх
 * страниц: `ATP_ISKLUCH_PRIG.txt` + `TICKET_ISKLUCH_PRIG.txt`
 * (пригородные, lgot_prig.php), `ATP_ISKLUCH.txt` (межобластные,
 * lgot.php); у внутриобластных (lgot2.php) списков не было вовсе.
 * Это настоящие рабочие данные: на боевом сервере в них накопились
 * исключённые АТП и билеты за годы, и без переноса отчёт на новой
 * платформе посчитал бы их заново.
 *
 * Источник — либо каталог с файлами, либо адрес старого АИС (файлы лежат
 * в его корне и отдаются по http).
 */
class ImportLgotExclusions extends Command
{
    protected $signature = 'lgot:import-legacy
        {--from= : каталог с файлами или адрес старого АИС (по умолчанию config legacy_lgot.source)}
        {--kind= : только этот вид рейса (prig|obl|mezh)}
        {--fresh : сначала очистить списки билетов переносимых видов}
        {--dry-run : только показать, что будет перенесено}';

    protected $description = 'Перенести списки исключений отчёта «Нулевые билеты» из файлов legacy';

    public function handle(LgotExclusions $exclusions): int
    {
        $from = rtrim((string) ($this->option('from') ?: config('legacy_lgot.source')), '/');

        if ($from === '') {
            $this->error('Не задан источник: --from=<каталог|http://адрес>');

            return self::FAILURE;
        }

        $only = $this->option('kind');

        if ($only !== null && ! LgotKind::exists($only)) {
            $this->error('Неизвестный вид рейса: '.$only.' (ожидается '.implode('|', array_keys(LgotKind::ALL)).')');

            return self::FAILURE;
        }

        $touched = false;

        foreach (LgotKind::ALL as $kind => $meta) {
            if ($only !== null && $only !== $kind) {
                continue;
            }

            if ($meta['legacy_atp'] === null && $meta['legacy_tickets'] === null) {
                $this->line($meta['title'].': списков в legacy не было — пропускаем.');

                continue;
            }

            $this->line('— '.$meta['title']);

            $atpRaw = $meta['legacy_atp'] === null ? null : $this->read($from, $meta['legacy_atp']);
            $tickRaw = $meta['legacy_tickets'] === null ? null : $this->read($from, $meta['legacy_tickets']);

            if ($atpRaw === null && $tickRaw === null) {
                continue;
            }

            // Список АТП legacy писал как есть, то есть в WIN1251.
            $names = $atpRaw === null ? [] : $exclusions->parseAtpList($this->toUtf8($atpRaw));
            $tickets = $this->parseTickets($tickRaw);

            $this->line('  АТП по договорам: '.count($names).($names ? ' ('.implode(', ', $names).')' : ''));
            $this->line('  исключённых билетов: '.count($tickets));

            if ($this->option('dry-run')) {
                continue;
            }

            $touched = true;

            if ($this->option('fresh')) {
                $exclusions->clearTickets($kind);
            }

            if ($atpRaw !== null) {
                $exclusions->saveAtpList($kind, implode("\n", $names));
            }

            $added = $exclusions->addTickets($kind, $tickets, 'legacy');

            $this->info(sprintf(
                '  перенесено: АТП %d, билетов добавлено %d (всего в списке %d).',
                count($names),
                $added,
                LgotExcludedTicket::where('kind', $kind)->count(),
            ));
        }

        if (! $touched && ! $this->option('dry-run')) {
            $this->warn('Ничего не перенесено.');
        }

        return self::SUCCESS;
    }

    /** Файл из каталога или по http. null — прочитать не удалось. */
    private function read(string $from, string $file): ?string
    {
        $path = $from.'/'.$file;

        // Чужой сервер может и не ответить — ошибку показываем словами,
        // а не стектрейсом: команду запускает человек.
        $raw = @file_get_contents($path, false, stream_context_create([
            'http' => ['timeout' => 30],
        ]));

        if ($raw === false) {
            $this->warn("  не прочитать {$path}");

            return null;
        }

        return $raw;
    }

    /**
     * Строки отчёта из файла legacy. Поля там уже в UTF-8 (json_encode с
     * JSON_UNESCAPED_UNICODE), перекодировать их нельзя.
     *
     * @return list<array<string, mixed>>
     */
    private function parseTickets(?string $raw): array
    {
        if ($raw === null || trim($raw) === '') {
            return [];
        }

        $rows = json_decode($raw, true);

        if (! is_array($rows)) {
            $this->warn('  файл исключённых билетов не разобрать как JSON — пропускаем.');

            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            if (is_array($row) && trim((string) ($row['tick_key'] ?? '')) !== '') {
                $out[] = $row;
            }
        }

        return $out;
    }

    /** Список АТП в файле legacy — WIN1251; валидный UTF-8 оставляем как есть. */
    private function toUtf8(string $raw): string
    {
        return mb_check_encoding($raw, 'UTF-8') ? $raw : (string) iconv('Windows-1251', 'UTF-8//IGNORE', $raw);
    }
}
