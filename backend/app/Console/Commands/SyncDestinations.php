<?php

namespace App\Console\Commands;

use App\Services\DestinationService;
use Illuminate\Console\Command;

/**
 * Обновление пунктов назначения станций (список «Куда» на форме поиска).
 * Запускается по расписанию ofelia в 07:00 и 10:00 МСК — до начала продаж
 * и в середине утра, чтобы правка на станции доехала до сайта в тот же
 * день, — а также кнопкой в АИС.
 */
class SyncDestinations extends Command
{
    protected $signature = 'destinations:sync {--awtcod= : Обновить только одну станцию}';

    protected $description = 'Обновить пункты назначения станций из API продаж';

    public function handle(DestinationService $destinations): int
    {
        $rows = $this->option('awtcod')
            ? [$destinations->sync((int) $this->option('awtcod'))]
            : $destinations->syncAll();

        $this->table(
            ['Станция', 'Пунктов', 'Ошибка'],
            array_map(fn (array $r) => [
                $r['name'].' ('.$r['awtcod'].')',
                $r['destinations'] ?? '—',
                $r['error'] ? mb_substr($r['error'], 0, 60) : '',
            ], $rows),
        );

        $failed = count(array_filter($rows, fn (array $r) => $r['error'] !== null));

        return $failed === count($rows) && $rows !== [] ? self::FAILURE : self::SUCCESS;
    }
}
