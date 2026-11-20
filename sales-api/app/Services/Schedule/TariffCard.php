<?php

namespace App\Services\Schedule;

use App\Exceptions\BusterException;
use App\Services\Buster\BusterGateway;

/**
 * Тариф рейса по остановкам (перенос legacy `/tarif` + action=tarif).
 *
 * ГЛАВНЫЙ НЮАНС — тарифных категорий у рейса может быть две.
 * В raicelst живут OLDNOMKAT, NEWNOMKAT и CHANDATE (дата смены):
 *   • CHANDATE в прошлом (или его нет) — тариф один, действует NEWNOMKAT;
 *   • CHANDATE в будущем — их два: до CHANDATE действует OLDNOMKAT,
 *     с CHANDATE — NEWNOMKAT.
 * Legacy требовал nomkat в адресе страницы и полагался на то, что его
 * правильно подставит вызывающая страница (расписание подставляло
 * действующий, а страница тарифа сама предлагала переключиться на новый).
 * Здесь категорию всегда выбирает сервер: клиент просит вариант
 * `current` (действующий) или `upcoming` (новый с даты).
 */
class TariffCard
{
    public const VARIANT_CURRENT = 'current';

    public const VARIANT_UPCOMING = 'upcoming';

    /**
     * @param  string|null  $variant  current|upcoming (по умолчанию действующий)
     * @param  string  $fromStation  остановка отправления на трассе (пусто — от станции)
     * @return array<string, mixed>
     */
    public function build(
        BusterGateway $gateway,
        int $awtcod,
        int $napcod,
        int $raicecod,
        ?string $variant = null,
        string $fromStation = '',
    ): array {
        $route = $gateway->routeCard($awtcod, $napcod, $raicecod);

        if (! $route) {
            throw new BusterException('Рейс не найден', 'route_not_found');
        }

        /*
         * Остановка отправления: тариф от неё живёт в своих строках
         * TARIFS2D (STARTSTATCODE = код остановки), и цены там другие —
         * от Дросково до Ливен 182 ₽ против 669 ₽ от Орла
         * (docs/BOARDING_POINTS.md). Названия остановки, которой нет в
         * этом направлении, не бывает — отказываем сразу, иначе страница
         * показала бы пустой тариф и выглядела как «цен нет».
         */
        $fromStatcod = 0;

        if ($fromStation !== '') {
            $fromStatcod = $gateway->stopCodeByName($awtcod, $napcod, $fromStation)
                ?? throw new BusterException(
                    'Остановка «'.$fromStation.'» на этом направлении не найдена',
                    'stop_not_found',
                );
        }

        $variants = $this->variants($route);

        $variant = $variant === self::VARIANT_UPCOMING && isset($variants['upcoming'])
            ? self::VARIANT_UPCOMING
            : self::VARIANT_CURRENT;

        $nomkat = $variants[$variant]['nomkat'];
        $rows = $gateway->tariff($napcod, $nomkat, $raicecod, $fromStatcod);

        $timeStart = substr((string) $route['TIMESTART'], 0, 5);
        $from = $this->fromStop($rows, $fromStation, $timeStart);

        return [
            'awtcod' => $awtcod,
            'napcod' => $napcod,
            'raicecod' => $raicecod,
            'route_name' => $route['RAICENAME'].'-'.$route['NAPNAME'],
            'trip_name' => $route['RAICENAME'],
            'direction_name' => $route['NAPNAME'],
            'route_number' => $route['MARSHNOM'] ?: null,
            'route_full_name' => $route['MARSHNAME'] ?: null,
            'carrier' => $route['ATPNAME'] ?: null,
            'charter' => (int) $route['RAICE_CATEGORY'] === 1,
            'time_start' => $timeStart,
            'variant' => $variant,
            'nomkat' => $nomkat,
            'change_date' => isset($variants['upcoming'])
                ? date('Y-m-d', strtotime((string) $route['CHANDATE']))
                : null,
            'variants' => $variants,
            /*
             * Откуда считается тариф. У продажи от станции это она сама
             * (`from_stop` = null), у продажи от остановки — её название,
             * километраж и ВРЕМЯ ПОСАДКИ: расстояния и время в пути в
             * таблице отсчитываются от неё, а не от начала трассы.
             */
            'from_stop' => $from,
            'stops' => array_map(
                fn (array $row) => $this->stop($row, $timeStart, $from),
                $rows,
            ),
        ];
    }

    /**
     * Остановка отправления: её километраж и время посадки.
     *
     * Берём из первой строки тарифа — они все с одним STARTSTATCODE.
     * Время стоянки у станций часто не заполнено, а в Firebird
     * `135 + null` даёт null, поэтому coalesce обязателен (та же ловушка,
     * что в поиске рейсов).
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<string, mixed>|null
     */
    private function fromStop(array $rows, string $fromStation, string $timeStart): ?array
    {
        if ($fromStation === '') {
            return null;
        }

        $row = $rows[0] ?? null;

        $toStat = $row !== null && trim((string) ($row['START_TIME_TO_STAT'] ?? '')) !== ''
            ? (int) $row['START_TIME_TO_STAT']
            : null;
        $stop = (int) ($row['START_TIME_OF_STOP'] ?? 0);
        $offset = $toStat === null ? null : $toStat + $stop;

        return [
            'name' => $row['STARTSTATNAME'] ?? $fromStation,
            // В базе расстояние в метрах (legacy делил на 1000).
            'km' => $row === null ? null : round(((float) $row['STARTKILOM']) / 1000, 2),
            // Время, когда автобус уходит С ОСТАНОВКИ (рейс отправляется раньше).
            'departure' => $offset === null ? null : date('H:i', strtotime($timeStart) + $offset * 60),
            'offset_minutes' => $offset,
            /*
             * Посадка в расписании ЭТОГО рейса может быть запрещена
             * (TRACETIME.SET_DISABLED): тариф тогда заведён, а купить
             * билет нельзя — страница должна сказать это прямо.
             */
            'boarding_allowed' => $row === null
                ? null
                : (int) ($row['START_SET_DISABLED'] ?? 1) === 0,
        ];
    }

    /**
     * Доступные версии тарифа.
     *
     * @return array{current: array{nomkat: int, valid_to: ?string}, upcoming?: array{nomkat: int, valid_from: string}}
     */
    private function variants(array $route): array
    {
        $changeAt = $route['CHANDATE'] ? strtotime((string) $route['CHANDATE']) : null;
        $today = strtotime(date('Y-m-d'));

        // Смены нет или она уже произошла — действует «новая» категория.
        if ($changeAt === null || $changeAt <= $today) {
            return [
                'current' => ['nomkat' => (int) $route['NEWNOMKAT'], 'valid_to' => null],
            ];
        }

        return [
            // Старый тариф действует ПО день перед сменой (legacy: chandate - 1 сутки).
            'current' => [
                'nomkat' => (int) $route['OLDNOMKAT'],
                'valid_to' => date('Y-m-d', $changeAt - 24 * 3600),
            ],
            'upcoming' => [
                'nomkat' => (int) $route['NEWNOMKAT'],
                'valid_from' => date('Y-m-d', $changeAt),
            ],
        ];
    }

    /**
     * Строка остановки: расстояние в км, время прибытия и время в пути.
     *
     * При продаже от остановки и расстояние, и время в пути считаются ОТ
     * НЕЁ: пассажиру из Дросково до Ливен 40 км и 45 минут, а не 148 км и
     * три часа от Орла. Время прибытия остаётся абсолютным — оно и так
     * привязано к рейсу.
     */
    private function stop(array $row, string $timeStart, ?array $from = null): array
    {
        /*
         * Времени в пути до остановки может не быть вовсе: `tracetime`
         * присоединён LEFT JOIN, и у части остановок строки в расписании
         * нет. Legacy приводил NULL к нулю (`tarif.php`: `date('H:i',
         * strtotime(TIMESTART) + TIME_TO_STAT*60)`) и печатал «прибытие =
         * время отправления, время в пути 0 ч. 0 мин.» — то есть автобус
         * будто приезжает, не выехав. Такие остановки честнее оставить без
         * времени: страница поставит прочерк.
         */
        $minutes = isset($row['TIME_TO_STAT']) && trim((string) $row['TIME_TO_STAT']) !== ''
            ? (int) $row['TIME_TO_STAT']
            : null;
        $arrivalAt = $minutes === null ? null : strtotime($timeStart) + $minutes * 60;

        // Время в пути от остановки отправления: минус время посадки.
        $wayMinutes = $minutes;
        if ($minutes !== null && $from !== null && $from['offset_minutes'] !== null) {
            $wayMinutes = max(0, $minutes - (int) $from['offset_minutes']);
        }

        $km = round(((float) $row['KILOM']) / 1000, 2);
        if ($from !== null && $from['km'] !== null) {
            $km = round($km - (float) $from['km'], 2);
        }

        return [
            'name' => $row['STATNAME'],
            'statcode' => (int) $row['STATCODE'],
            // В базе расстояние в метрах (legacy делил на 1000).
            'km' => $km,
            'price_full' => (float) $row['PAYHARDALL'],
            'price_child' => (float) $row['PAYHARDCHD'],
            'price_baggage' => (float) $row['PAYHARDBAG'],
            'insurance' => (float) $row['DOBRSTR'],
            'arrival' => $arrivalAt === null ? null : date('H:i', $arrivalAt),
            /*
             * На какие сутки от отправления приходится прибытие: 0 — в
             * день отправления, 1 — на следующие. Время само по себе
             * этого не говорит, а рейсы бывают дольше суток (Орёл–
             * Симферополь: 1535 минут, отправление 11:15, прибытие 12:50
             * СЛЕДУЮЩЕГО дня). Legacy печатал одно время (`tarif.php`:
             * `date('H:i', strtotime(TIMESTART) + TIME_TO_STAT*60)`),
             * и на таких рейсах таблицу было не прочитать.
             */
            'arrival_day_offset' => $minutes === null ? 0 : intdiv(
                (int) date('H', strtotime($timeStart)) * 3600
                + (int) date('i', strtotime($timeStart)) * 60
                + $minutes * 60,
                86400,
            ),
            'time_way_minutes' => $wayMinutes,
            'time_way' => $wayMinutes === null
                ? null
                : intdiv($wayMinutes, 60).' ч. '.($wayMinutes % 60).' мин.',
            'stop_minutes' => (int) $row['TIME_OF_STOP'],
        ];
    }
}
