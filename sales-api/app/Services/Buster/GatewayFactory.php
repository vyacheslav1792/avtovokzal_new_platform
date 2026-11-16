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

    /**
     * Пауза перед следующей попыткой подключиться к станции, которая
     * только что отказала.
     *
     * Зачем: таймаут спасает лишь от ОБРЫВА канала. У Кром, например,
     * порт отвечает мгновенно (на 192.168.4.250 слушает туннель), а
     * отказ «connection rejected by remote interface» приходит **через
     * 21 секунду** — это ответ с другой стороны, и укоротить его нечем.
     * Запросов к такой станции идёт по 150–190 в час, то есть обработчик
     * php-fpm занят ею почти постоянно; при обрыве сразу у всех станций
     * свободных обработчиков не осталось вовсе (16.11.2026).
     *
     * Поэтому к станции, которая только что отказала, мы минуту не
     * ходим: покупатель получает «автостанция недоступна» сразу, а не
     * через 21 секунду. Цена — станция, ожившая секунду назад, числится
     * недоступной ещё до минуты.
     */
    private const RETRY_PAUSE_SECONDS = 60;

    public function __construct(
        private readonly StationRegistry $stations,
        private readonly SiteSettings $settings,
    ) {}

    public function forStation(int|string $awtcod, array $agent): BusterGateway
    {
        $station = $this->stations->get($awtcod);
        $offline = Cache::get(self::offlineKey($awtcod));

        // Станция отказала только что — не ходим к ней снова, пока не
        // вышла пауза: её отказ занимает обработчик на 21 секунду.
        if (is_array($offline) && CarbonImmutable::now()->timestamp < (int) ($offline['retry_after'] ?? 0)) {
            throw new StationUnavailableException(
                (int) $awtcod,
                (string) ($station['name'] ?? $awtcod),
                (string) ($offline['reason'] ?? 'связи нет'),
            );
        }

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
            $this->noteOffline($station, $e, is_array($offline) ? $offline : null);

            throw new StationUnavailableException(
                (int) $awtcod,
                (string) ($station['name'] ?? $awtcod),
                $e->getMessage(),
            );
        }

        if (is_array($offline)) {
            $this->noteOnline($station, $offline);
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
     * Связь пропала — одна строка в журнал, без стектрейса (он в таких
     * отказах одинаковый и ничего не добавляет).
     *
     * Пишем именно ПЕРЕХОД, а не каждый отказ: у станции без связи
     * запросы идут сотнями в час, и прежний вариант (одна строка раз в
     * пять минут) всё равно засыпал журнал повторами об одном и том же.
     */
    /**
     * @param  array<string, mixed>|null  $previous  прежняя отметка, если станция уже числилась недоступной
     */
    private function noteOffline(array $station, PDOException $e, ?array $previous): void
    {
        $now = CarbonImmutable::now();
        $reason = mb_substr($e->getMessage(), 0, 200);

        Cache::put(
            self::offlineKey($station['awtcod'] ?? 'unknown'),
            [
                // Время ПЕРВОГО отказа не сдвигаем: по нему считается,
                // сколько длился сбой.
                'since' => (string) ($previous['since'] ?? $now->toDateTimeString()),
                'retry_after' => $now->timestamp + self::RETRY_PAUSE_SECONDS,
                'reason' => $reason,
            ],
            now()->addHours(self::OFFLINE_TTL_HOURS),
        );

        // В журнал — только ПЕРЕХОД: прежней отметки не было, значит до
        // этого станция считалась доступной.
        if ($previous !== null) {
            return;
        }

        Log::warning('Связь с базой станции пропала', [
            'awtcod' => $station['awtcod'] ?? null,
            'station' => $station['name'] ?? null,
            'host' => ($station['db_host'] ?? '?').':'.($station['db_port'] ?? '?'),
            'reason' => $reason,
        ]);
    }

    /**
     * Связь появилась. Запись нужна не меньше, чем об отказе: без неё по
     * журналу не понять, кончился сбой или просто перестали спрашивать
     * эту станцию.
     */
    private function noteOnline(array $station, array $offline): void
    {
        Cache::forget(self::offlineKey($station['awtcod'] ?? 'unknown'));

        $started = CarbonImmutable::parse((string) ($offline['since'] ?? CarbonImmutable::now()));
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
