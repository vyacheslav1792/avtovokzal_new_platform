<?php

namespace App\Console\Commands;

use App\Services\BoardingPointDestinationService;
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

    public function handle(
        DestinationService $destinations,
        BoardingPointDestinationService $stopDestinations,
    ): int {
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

        /*
         * Пункты посадки обновляем тем же запуском: список «Куда» у
         * остановки живёт по тем же правилам, а отдельное расписание
         * означало бы, что новая остановка появляется на сайте не тогда,
         * когда станция завела тариф (docs/BOARDING_POINTS.md).
         * При `--awtcod` обновляем привязки только этой станции.
         */
        $stopRows = array_filter(
            $stopDestinations->syncAll(),
            fn (array $r) => ! $this->option('awtcod') || $r['awtcod'] === (int) $this->option('awtcod'),
        );

        if ($stopRows !== []) {
            $this->newLine();
            $this->table(
                ['Пункт посадки', 'Остановка', 'Станция', 'Пунктов', 'Ошибка'],
                array_map(fn (array $r) => [
                    $r['slug'],
                    $r['station_name'],
                    $r['awtcod'],
                    $r['destinations'] ?? '—',
                    $r['error'] ? mb_substr($r['error'], 0, 60) : '',
                ], $stopRows),
            );
        }

        $failed = count(array_filter($rows, fn (array $r) => $r['error'] !== null));

        return $failed === count($rows) && $rows !== [] ? self::FAILURE : self::SUCCESS;
    }
}
