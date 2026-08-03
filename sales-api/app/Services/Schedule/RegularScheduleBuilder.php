<?php

namespace App\Services\Schedule;

use App\Services\Buster\BusterGateway;

/**
 * Регулярное расписание станции в виде данных (перенос legacy
 * `ais/api/api2.php`, action=raspisanie, где на выходе была готовая
 * HTML-таблица — её сайт складывал в файл url/raspisanie/*.html).
 *
 * Логика графика перенесена 1:1:
 *  - REG_FIXED           → нерегулярный рейс, даты из reg_fixed_days;
 *  - ни одного дня недели → рейс в расписание не попадает;
 *  - все семь дней       → «Каждый день», а с REG_INTERVAL — «Каждые N дня»
 *                          плюс две ближайшие даты (расчёт от 11.05.2018);
 *  - иначе               → перечисление дней недели.
 * Рейсы с одинаковым маршрутом и графиком объединяются в одну строку
 * со списком времён отправления — как в legacy (ключ RAICENAME-NAPNAME||график).
 */
class RegularScheduleBuilder
{
    /** День периода в зависимости от интервала — таблица legacy на 11.05.2018. */
    private const PERIOD = [1 => 1, 2 => 2, 3 => 2, 4 => 4, 5 => 2, 6 => 2, 7 => 7, 8 => 8, 9 => 5, 10 => 2];

    /** Опорная дата расчёта интервальных рейсов (legacy $nd). */
    private const INTERVAL_EPOCH = '2018-05-11';

    private const WEEK_DAYS = [
        'REG_MONDAY' => ['mon', 'Пн'],
        'REG_TUESDAY' => ['tue', 'Вт'],
        'REG_WEDNESDAY' => ['wed', 'Ср'],
        'REG_THURSDAY' => ['thu', 'Чт'],
        'REG_FRIDAY' => ['fri', 'Пт'],
        'REG_SATURDAY' => ['sat', 'Сб'],
        'REG_SUNDAY' => ['sun', 'Вс'],
    ];

    /**
     * @return array{routes: array<int, array<string, mixed>>, generated_at: string}
     */
    public function build(BusterGateway $gateway, int $awtcod): array
    {
        $fixedDays = $this->fixedDays($gateway, $awtcod);
        $grouped = [];

        foreach ($gateway->regularSchedule($awtcod) as $row) {
            $schedule = $this->schedule($row, $fixedDays);

            // Рейс без единого дня недели и без фиксированных дат — не расписание.
            if ($schedule === null) {
                continue;
            }

            $routeName = $row['RAICENAME'].'-'.$row['NAPNAME'];
            $key = $routeName.'||'.$schedule['text'];

            $grouped[$key] ??= [
                'route_name' => $routeName,
                'trip_name' => $row['RAICENAME'],
                'direction_name' => $row['NAPNAME'],
                'napcod' => (int) $row['NAPCOD'],
                'route_number' => $row['MARSHNOM'] ?: null,
                'route_full_name' => $row['MARSHNAME'] ?: null,
                'carrier' => $row['ATPNAME'] ?: null,
                'schedule' => $schedule['text'],
                'days' => $schedule['days'],
                'interval' => $schedule['interval'],
                'fixed_dates' => $schedule['fixed_dates'],
                'next_dates' => $schedule['next_dates'],
                'departures' => [],
            ];

            $grouped[$key]['departures'][] = [
                'time' => substr((string) $row['TIMESTART'], 0, 5),
                'raicecod' => (int) $row['RAICECOD'],
                // Номер категории тарифа: пока не наступила дата смены
                // (CHANDATE), действует прежняя категория — правило legacy.
                'nomkat' => $this->tariffCategory($row),
                // RAICE_CATEGORY = 1 — заказной рейс (legacy помечал «(заказной)»).
                'charter' => (int) $row['RAICE_CATEGORY'] === 1,
            ];
        }

        $routes = array_values($grouped);

        foreach ($routes as &$route) {
            usort($route['departures'], fn (array $a, array $b) => strcmp($a['time'], $b['time']));
        }

        return [
            'routes' => $routes,
            'generated_at' => now()->toIso8601String(),
        ];
    }

    /**
     * @return array<string, string[]> ключ NAPCOD|RAICECOD → даты d.m.Y
     */
    private function fixedDays(BusterGateway $gateway, int $awtcod): array
    {
        $map = [];

        foreach ($gateway->fixedDays($awtcod) as $row) {
            $map[$row['NAPCOD'].'|'.$row['RAICECOD']][] = date('d.m.Y', strtotime((string) $row['DATESTART']));
        }

        return $map;
    }

    /**
     * График рейса.
     *
     * @return array{text: string, days: string[], interval: ?int, fixed_dates: string[], next_dates: string[]}|null
     */
    private function schedule(array $row, array $fixedDays): ?array
    {
        if ((int) $row['REG_FIXED'] === 1) {
            $dates = $fixedDays[$row['NAPCOD'].'|'.$row['RAICECOD']] ?? [];

            return [
                'text' => 'Нерегулярный: '.implode(', ', $dates),
                'days' => [],
                'interval' => null,
                'fixed_dates' => $dates,
                'next_dates' => [],
            ];
        }

        $days = [];
        $labels = [];
        foreach (self::WEEK_DAYS as $column => [$code, $label]) {
            if ((int) $row[$column] === 1) {
                $days[] = $code;
                $labels[] = $label;
            }
        }

        if ($days === []) {
            return null;
        }

        if (count($days) < 7) {
            return [
                'text' => implode(', ', $labels),
                'days' => $days,
                'interval' => null,
                'fixed_dates' => [],
                'next_dates' => [],
            ];
        }

        $interval = (int) ($row['REG_INTERVAL'] ?? 0);

        if ($interval === 0) {
            return [
                'text' => 'Каждый день',
                'days' => $days,
                'interval' => null,
                'fixed_dates' => [],
                'next_dates' => [],
            ];
        }

        return [
            'text' => 'Каждые '.$interval.' дня',
            'days' => $days,
            'interval' => $interval,
            'fixed_dates' => [],
            'next_dates' => $this->nextDates($interval, (int) ($row['REG_STARTINTERVAL'] ?? 0)),
        ];
    }

    /**
     * Две ближайшие даты интервального рейса. Формула legacy: день периода
     * отсчитывается от 11.05.2018, таблица PERIOD задаёт, какой день периода
     * считается «рейсовым».
     *
     * @return string[] даты d.m.Y
     */
    private function nextDates(int $interval, int $startInterval): array
    {
        if (! isset(self::PERIOD[$interval])) {
            return [];
        }

        $epoch = strtotime(self::INTERVAL_EPOCH);
        $tomorrow = time() + 24 * 3600;
        $daysPassed = (int) floor(($tomorrow - $epoch) / (3600 * 24)) + 1;

        $dayInPeriod = $daysPassed % $interval + $startInterval;
        if ($dayInPeriod > $interval) {
            $dayInPeriod -= $interval;
        }

        $inDays = self::PERIOD[$interval] - $dayInPeriod;
        if ($inDays < 0) {
            $inDays += $interval;
        }

        $first = $tomorrow + 24 * 3600 * ($inDays - 1);

        return [
            date('d.m.Y', $first),
            date('d.m.Y', $first + $interval * 24 * 3600),
        ];
    }

    /** Категория тарифа: до CHANDATE действует прежняя (legacy OLDNOMKAT). */
    private function tariffCategory(array $row): int
    {
        $changeAt = $row['CHANDATE'] ? strtotime((string) $row['CHANDATE']) : null;

        return $changeAt !== null && $changeAt > strtotime(date('Y-m-d'))
            ? (int) $row['OLDNOMKAT']
            : (int) $row['NEWNOMKAT'];
    }
}
