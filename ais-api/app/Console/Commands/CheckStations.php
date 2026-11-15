<?php

namespace App\Console\Commands;

use App\Models\Station;
use App\Services\ConnectionTester;
use Illuminate\Console\Command;

/**
 * Проверка связи с базами станций — тем же способом, что кнопка в АИС
 * (результат пишется в профиль и виден в списке).
 *
 * Нужна после переноса справочника и после переключения подключений: 21
 * станцию кнопками проверять долго, а знать, кто отвечает, надо до
 * включения продажи.
 */
class CheckStations extends Command
{
    protected $signature = 'stations:check
        {--only= : только эти коды, через запятую}
        {--all-profiles : проверить все варианты подключения, а не только действующий}';

    protected $description = 'Проверить связь с базами станций (результат пишется в справочник)';

    public function handle(ConnectionTester $tester): int
    {
        $only = array_filter(array_map('trim', explode(',', (string) $this->option('only'))));

        $stations = Station::with('profiles')
            ->when($only !== [], fn ($q) => $q->whereIn('awtcod', $only))
            ->orderBy('inlist')
            ->get();

        if ($stations->isEmpty()) {
            $this->warn('В справочнике нет станций — загрузите их командой stations:import.');

            return self::SUCCESS;
        }

        $rows = [];
        $failed = 0;

        foreach ($stations as $station) {
            $profiles = $this->option('all-profiles')
                ? $station->profiles
                : $station->profiles->where('slot', $station->active_profile);

            foreach ($profiles as $profile) {
                $result = $tester->station($profile);
                $active = $profile->slot === $station->active_profile;

                if ($active && ! $result['ok']) {
                    $failed++;
                }

                $rows[] = [
                    $station->awtcod,
                    $station->name.($station->is_active ? '' : ' (отключена)'),
                    $profile->slot.($active ? ' ←' : '').' '.$profile->label,
                    $profile->address(),
                    $result['ok'] ? 'ок '.($result['info']['ms'] ?? '?').' мс' : 'НЕТ СВЯЗИ',
                    $result['ok'] ? '' : mb_substr((string) $result['error'], 0, 60),
                ];
            }
        }

        $this->table(['Код', 'Станция', 'Вариант', 'Адрес', 'Связь', 'Ошибка'], $rows);

        if ($failed > 0) {
            $this->warn("Не отвечают действующие подключения: {$failed}.");
            $this->line('Это может быть сбой связи на месте — справочник при этом в порядке.');
        }

        return self::SUCCESS;
    }
}
