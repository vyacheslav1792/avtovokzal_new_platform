<?php

namespace App\Support;

/**
 * Вид рейса в отчёте «Нулевые билеты». В legacy это были ТРИ отдельные
 * страницы с почти одинаковым кодом — `ais/lgot_prig.php` (пригородные),
 * `ais/lgot2.php` (внутриобластные) и `ais/lgot.php` (межобластные);
 * различались они видом маршрута, списками исключений и мелочами формы.
 *
 * Здесь собрано всё, чем виды отличаются ДРУГ ОТ ДРУГА, — остальное у
 * отчёта общее.
 */
final class LgotKind
{
    public const PRIG = 'prig';

    public const OBL = 'obl';

    public const MEZH = 'mezh';

    /**
     * Виды рейсов по порядку показа.
     *
     * - `title` — подпись в форме;
     * - `martypcod` — условие по виду маршрута (EVRYRAICE.MARTYPCOD);
     * - `printed_only` — считать только напечатанные билеты
     *   (`SALE.IS_PRN=1`);
     * - `legacy_atp` / `legacy_tickets` — файлы legacy, из которых
     *   переносятся списки исключений (null — списка там не было).
     *
     * @var array<string, array{title: string, martypcod: string, printed_only: bool, legacy_atp: ?string, legacy_tickets: ?string}>
     */
    public const ALL = [
        self::PRIG => [
            'title' => 'Пригородные',
            'martypcod' => 'EVRYRAICE.martypcod=0',
            /*
             * Только напечатанные — так было в legacy lgot_prig.php, и в
             * подсказке отчёта это сказано прямо: «учитываются билеты
             * фактически уехавшие». У двух других видов такого условия в
             * legacy НЕ БЫЛО (lgot.php и lgot2.php считают и
             * нераспечатанные), и мы это сохраняем: по этим отчётам
             * рассчитываются с перевозчиками, и менять суммы молча нельзя.
             */
            'printed_only' => true,
            'legacy_atp' => 'ATP_ISKLUCH_PRIG.txt',
            'legacy_tickets' => 'TICKET_ISKLUCH_PRIG.txt',
        ],
        self::OBL => [
            'title' => 'Внутриобластные',
            'martypcod' => 'EVRYRAICE.martypcod=1',
            'printed_only' => false,
            // У lgot2.php списков исключений не было вовсе — ни файла АТП,
            // ни файла билетов. В новом отчёте они у него появляются
            // (пустые), чтобы форма у всех видов была одинаковой.
            'legacy_atp' => null,
            'legacy_tickets' => null,
        ],
        self::MEZH => [
            'title' => 'Межобластные',
            'martypcod' => 'EVRYRAICE.martypcod>1',
            'printed_only' => false,
            'legacy_atp' => 'ATP_ISKLUCH.txt',
            // Списка исключённых билетов у lgot.php не было.
            'legacy_tickets' => null,
        ],
    ];

    /** Вид по умолчанию — пригородные (самый ходовой из трёх). */
    public const DEFAULT = self::PRIG;

    public static function exists(string $kind): bool
    {
        return isset(self::ALL[$kind]);
    }

    /** Вид, приведённый к известному: мусор на входе — пригородные. */
    public static function normalize(?string $kind): string
    {
        return self::exists((string) $kind) ? (string) $kind : self::DEFAULT;
    }

    public static function title(string $kind): string
    {
        return self::ALL[self::normalize($kind)]['title'];
    }

    /** Условие WHERE, которым вид рейса и отличается от остальных. */
    public static function condition(string $kind): string
    {
        $meta = self::ALL[self::normalize($kind)];

        return $meta['martypcod'].($meta['printed_only'] ? ' and sale.is_prn=1' : '');
    }

    /** Опции селекта «Рейсы». */
    public static function selectOptions(): array
    {
        $options = [];
        foreach (self::ALL as $kind => $meta) {
            $options[] = ['value' => $kind, 'label' => $meta['title']];
        }

        return $options;
    }
}
