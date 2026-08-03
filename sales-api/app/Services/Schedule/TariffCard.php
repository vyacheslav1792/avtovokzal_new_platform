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
     * @return array<string, mixed>
     */
    public function build(
        BusterGateway $gateway,
        int $awtcod,
        int $napcod,
        int $raicecod,
        ?string $variant = null,
    ): array {
        $route = $gateway->routeCard($awtcod, $napcod, $raicecod);

        if (! $route) {
            throw new BusterException('Рейс не найден', 'route_not_found');
        }

        $variants = $this->variants($route);

        $variant = $variant === self::VARIANT_UPCOMING && isset($variants['upcoming'])
            ? self::VARIANT_UPCOMING
            : self::VARIANT_CURRENT;

        $nomkat = $variants[$variant]['nomkat'];
        $rows = $gateway->tariff($napcod, $nomkat, $raicecod);

        $timeStart = substr((string) $route['TIMESTART'], 0, 5);

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
            'stops' => array_map(
                fn (array $row) => $this->stop($row, $timeStart),
                $rows,
            ),
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

    /** Строка остановки: расстояние в км, время прибытия и время в пути. */
    private function stop(array $row, string $timeStart): array
    {
        $minutes = (int) $row['TIME_TO_STAT'];
        $arrivalAt = strtotime($timeStart) + $minutes * 60;

        return [
            'name' => $row['STATNAME'],
            'statcode' => (int) $row['STATCODE'],
            // В базе расстояние в метрах (legacy делил на 1000).
            'km' => round(((float) $row['KILOM']) / 1000, 2),
            'price_full' => (float) $row['PAYHARDALL'],
            'price_child' => (float) $row['PAYHARDCHD'],
            'price_baggage' => (float) $row['PAYHARDBAG'],
            'insurance' => (float) $row['DOBRSTR'],
            'arrival' => date('H:i', $arrivalAt),
            'time_way_minutes' => $minutes,
            'time_way' => intdiv($minutes, 60).' ч. '.($minutes % 60).' мин.',
            'stop_minutes' => (int) $row['TIME_OF_STOP'],
        ];
    }
}
