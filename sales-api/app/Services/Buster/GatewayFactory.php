<?php

namespace App\Services\Buster;

use App\Exceptions\StationUnavailableException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use PDOException;

class GatewayFactory
{
    /**
     * Как часто пишем в журнал об одной и той же недоступной станции.
     * Без этого отказы идут сотнями в час (у станции пропал интернет), и
     * журнал за сутки распухал на десятки мегабайт стектрейсами.
     */
    private const LOG_SILENCE_MINUTES = 5;

    public function __construct(private readonly StationRegistry $stations) {}

    public function forStation(int|string $awtcod, array $agent): BusterGateway
    {
        $station = $this->stations->get($awtcod);

        try {
            $connection = new BusterConnection($station);
        } catch (PDOException $e) {
            /*
             * До базы станции не дошли: нет связи, сервер выключен, база
             * не отвечает. Это не сбой нашего приложения — отдаём
             * распознаваемый отказ, по которому сайт покажет покупателю
             * «станция недоступна», а не «Server Error».
             */
            $this->logUnavailable($station, $e);

            throw new StationUnavailableException(
                (int) $awtcod,
                (string) ($station['name'] ?? $awtcod),
                $e->getMessage(),
            );
        }

        return new BusterGateway(
            $connection,
            (int) $agent['legacy_id'],
            (int) ($agent['worker_code'] ?? 990),
            (int) ($agent['baggage_worker_code'] ?? 991),
            config('sale_bounds', []),
        );
    }

    /**
     * Одна короткая строка на станцию раз в несколько минут — без
     * стектрейса: он в таких отказах одинаковый и ничего не добавляет.
     */
    private function logUnavailable(array $station, PDOException $e): void
    {
        $key = 'station-unavailable-logged:'.($station['awtcod'] ?? 'unknown');

        if (! Cache::add($key, true, now()->addMinutes(self::LOG_SILENCE_MINUTES))) {
            return;
        }

        Log::warning('Станция недоступна: нет связи с её базой продаж', [
            'awtcod' => $station['awtcod'] ?? null,
            'station' => $station['name'] ?? null,
            'host' => ($station['db_host'] ?? '?').':'.($station['db_port'] ?? '?'),
            'reason' => mb_substr($e->getMessage(), 0, 200),
        ]);
    }
}
