<?php

namespace App\Console\Commands;

use App\Models\Station;
use App\Models\StationProfile;
use App\Services\StationCatalog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Наполнение справочника станций из файла с боевыми реквизитами
 * (`database/data/stations.json`, снято с ais/api/config.php).
 *
 * Нужна при развёртывании: справочник правится в АИС, но первый раз его
 * надо чем-то заполнить, а 21 станция × 3 варианта подключения руками —
 * это 63 формы и неизбежная опечатка в пароле.
 *
 * Повторный запуск по умолчанию НЕ трогает уже существующие станции:
 * настройки могли поправить в АИС, и файл их перетирать не должен.
 * `--force` перезаписывает (ключи включения при этом сохраняются).
 */
class ImportStations extends Command
{
    protected $signature = 'stations:import
        {--file= : путь к json (по умолчанию database/data/stations.json)}
        {--force : перезаписать уже существующие станции}
        {--only= : только эти коды, через запятую}';

    protected $description = 'Загрузить станции и варианты подключения к их базам из файла';

    public function handle(StationCatalog $catalog): int
    {
        $path = $this->option('file') ?: database_path('data/stations.json');

        if (! is_file($path)) {
            $this->error("Файл не найден: {$path}");

            return self::FAILURE;
        }

        $data = json_decode((string) file_get_contents($path), true);

        if (! is_array($data) || ! isset($data['stations'])) {
            $this->error('Файл не похож на справочник станций: нет ключа stations.');

            return self::FAILURE;
        }

        $only = array_filter(array_map('trim', explode(',', (string) $this->option('only'))));
        $force = (bool) $this->option('force');

        $created = $updated = $skipped = 0;

        foreach ($data['stations'] as $row) {
            $code = (int) $row['awtcod'];

            if ($only !== [] && ! in_array((string) $code, $only, true)) {
                continue;
            }

            $existing = Station::find($code);

            if ($existing && ! $force) {
                $skipped++;
                continue;
            }

            DB::transaction(function () use ($row, $code, $existing) {
                $station = Station::updateOrCreate(['awtcod' => $code], [
                    'name' => $row['name'],
                    'buh' => $row['buh'] ?? null,
                    'buh_st' => $row['buh_st'] ?? null,
                    'buh_ksr' => $row['buh_ksr'] ?? null,
                    'stationkey' => $row['stationkey'] ?? null,
                    'inlist' => (int) ($row['inlist'] ?? 0),
                    /*
                     * Выключатели берём из файла только у НОВОЙ станции.
                     * У существующей их могли переключить в АИС — файл не
                     * должен внезапно включить продажу.
                     */
                    'is_active' => $existing->is_active ?? (bool) ($row['is_active'] ?? true),
                    'sales_enabled' => $existing->sales_enabled ?? (bool) ($row['sales_enabled'] ?? false),
                    'refunds_enabled' => $existing->refunds_enabled ?? (bool) ($row['refunds_enabled'] ?? false),
                    'active_profile' => $existing->active_profile ?? (int) ($row['active_profile'] ?? 1),
                ]);

                foreach ($row['profiles'] ?? [] as $profile) {
                    StationProfile::updateOrCreate(
                        ['station_awtcod' => $station->awtcod, 'slot' => (int) $profile['slot']],
                        [
                            'label' => $profile['label'],
                            'db_host' => $profile['db_host'],
                            'db_port' => (int) $profile['db_port'],
                            'db_name' => $profile['db_name'],
                            'db_user' => $profile['db_user'],
                            'db_password' => (string) $profile['db_password'],
                            'notes' => $profile['notes'] ?? null,
                        ],
                    );
                }
            });

            $existing ? $updated++ : $created++;
        }

        $catalog->forget();

        $this->info("Станции: добавлено {$created}, обновлено {$updated}, пропущено {$skipped}.");

        if ($skipped > 0) {
            $this->line('Пропущенные уже есть в справочнике — перезаписать их можно ключом --force.');
        }

        return self::SUCCESS;
    }
}
