<?php

namespace App\Services\Buster;

use App\Exceptions\StationUnavailableException;
use App\Services\SiteSettings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use PDOException;

class GatewayFactory
{
    /**
     * Сколько держится отметка «станция числится недоступной».
     *
     * Она решает, писать ли в журнал: запись появляется в момент, когда
     * связь ПРОПАЛА, и второй раз — когда ПОЯВИЛАСЬ. Пока отметка жива,
     * повторные отказы молчат. Полсуток — чтобы станция без связи вовсе
     * (у Кром интернета нет) давала не больше двух строк в сутки, а не
     * одну каждые пять минут, как было раньше.
     */
    private const OFFLINE_TTL_HOURS = 12;

    public function __construct(
        private readonly StationRegistry $stations,
        private readonly SiteSettings $settings,
    ) {}

    public function forStation(int|string $awtcod, array $agent): BusterGateway
    {
        $station = $this->stations->get($awtcod);

        try {
            $connection = new BusterConnection(
                $station,
                // Сколько ждать связи — настройка из АИС («Настройки»):
                // станции сидят на разных каналах, и «сколько это
                // нормально» знает тот, кто видит сеть.
                $this->settings->stationConnectTimeout(),
            );
        } catch (PDOException $e) {
            /*
             * До базы станции не дошли: нет связи, сервер выключен, база
             * не отвечает. Это не сбой нашего приложения — отдаём
             * распознаваемый отказ, по которому сайт покажет покупателю
             * «станция недоступна», а не «Server Error».
             */
            $this->noteOffline($station, $e);

            throw new StationUnavailableException(
                (int) $awtcod,
                (string) ($station['name'] ?? $awtcod),
                $e->getMessage(),
            );
        }

        $this->noteOnline($station);

        return new BusterGateway(
            $connection,
            (int) $agent['legacy_id'],
            (int) ($agent['worker_code'] ?? 990),
            (int) ($agent['baggage_worker_code'] ?? 991),
            config('sale_bounds', []),
        );
    }

    /**
     * Связь пропала — одна строка в журнал, без стектрейса (он в таких
     * отказах одинаковый и ничего не добавляет).
     *
     * Пишем именно ПЕРЕХОД, а не каждый отказ: у станции без связи
     * запросы идут сотнями в час, и прежний вариант (одна строка раз в
     * пять минут) всё равно засыпал журнал повторами об одном и том же.
     */
    private function noteOffline(array $station, PDOException $e): void
    {
        $key = self::offlineKey($station['awtcod'] ?? 'unknown');

        // add() ставит отметку только если её не было, то есть true
        // здесь значит «до этого станция считалась доступной».
        $fresh = Cache::add(
            $key,
            CarbonImmutable::now()->toDateTimeString(),
            now()->addHours(self::OFFLINE_TTL_HOURS),
        );

        if (! $fresh) {
            return;
        }

        Log::warning('Связь с базой станции пропала', [
            'awtcod' => $station['awtcod'] ?? null,
            'station' => $station['name'] ?? null,
            'host' => ($station['db_host'] ?? '?').':'.($station['db_port'] ?? '?'),
            'reason' => mb_substr($e->getMessage(), 0, 200),
        ]);
    }

    /**
     * Связь появилась. Запись нужна не меньше, чем об отказе: без неё по
     * журналу не понять, кончился сбой или просто перестали спрашивать
     * эту станцию.
     */
    private function noteOnline(array $station): void
    {
        $since = Cache::pull(self::offlineKey($station['awtcod'] ?? 'unknown'));

        if ($since === null) {
            return;
        }

        $started = CarbonImmutable::parse((string) $since);
        $seconds = (int) round($started->diffInSeconds(CarbonImmutable::now()));

        Log::info('Связь с базой станции восстановлена', [
            'awtcod' => $station['awtcod'] ?? null,
            'station' => $station['name'] ?? null,
            'host' => ($station['db_host'] ?? '?').':'.($station['db_port'] ?? '?'),
            'offline_since' => $started->toDateTimeString(),
            // Готовая строка, а не дробное число минут: эту запись читают
            // глазами, когда разбираются, сколько длился сбой.
            'offline_for' => $seconds < 60 ? $seconds.' с' : round($seconds / 60).' мин',
        ]);
    }

    private static function offlineKey(int|string $awtcod): string
    {
        return 'station-offline:'.$awtcod;
    }
}
