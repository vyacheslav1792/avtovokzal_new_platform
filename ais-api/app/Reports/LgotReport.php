<?php

namespace App\Reports;

use App\Services\LgotExclusions;
use App\Services\SiteSettings;
use App\Services\StationConnection;
use App\Services\StationRegistry;
use App\Support\LgotKind;
use Illuminate\Support\Facades\Validator;

/**
 * Отчёт «Нулевые билеты» — перенос ТРЁХ страниц legacy с почти
 * одинаковым кодом: `ais/lgot_prig.php` + `ais/lgot_prig_api.php`
 * (пригородные), `ais/lgot2.php` (внутриобластные) и `ais/lgot.php`
 * (межобластные). Различались они видом маршрута и списками исключений,
 * поэтому здесь это один отчёт с фильтром «Рейсы» (см. LgotKind).
 *
 * Считает билеты с нулевой стоимостью проезда (проездные и социальные)
 * за период. Деньги за них АТП возмещают по тарифу
 * (TARIFS2D.PAYHARDALL/PAYHARDCHD — взрослый/детский), поэтому в выдаче
 * есть и возраст пассажира, и номер удостоверения.
 *
 * У отчёта своя страница в АИС (ais-spa /lgot): кроме таблицы в нём есть
 * отметка билетов к исключению, два списка исключений и выгрузка в
 * HTML — универсальным рендером отчётов это не нарисовать. Контракт
 * ответа всё равно стандартный (sections/columns/rows/totals), а то, что
 * нужно только этой странице, лежит отдельными ключами.
 *
 * Отличия от legacy:
 *  - три страницы сведены в одну, вид рейса выбирается фильтром;
 *  - список АТП запрашивается ОТДЕЛЬНЫМ запросом (см. atpList()), поэтому
 *    требование legacy «при смене периода сначала посчитать по всем АТП»
 *    снято: селект АТП наполняется сам;
 *  - «Социальный» отбирается как LGOTCOD IN (1,2) — см. lgotCondition();
 *  - колонки «Льгота», «Серия», фильтр по типу билета, список исключённых
 *    билетов и «Скрыть ФИО» были не у всех трёх страниц — теперь они
 *    общие (в legacy это была разница кода, а не смысла);
 *  - списки исключений живут в БД, а не в файлах рядом с кодом, и у
 *    каждого вида рейса они СВОИ.
 */
class LgotReport implements Report
{
    /** Возраст, с которого билет считается взрослым (в legacy — тоже 12). */
    private const ADULT_AGE = 12;

    /**
     * Нулевые билеты: общая для всех трёх страниц legacy часть условия —
     * без багажа, не интернет-продажа, не вычеркнут, не возвращён.
     * Вид маршрута и «только напечатанные» добавляет LgotKind: у
     * пригородных в legacy стояло `sale.is_prn=1`, у двух других — нет.
     */
    private const TICKET_CONDITION = 'PRICE_PLACE=0 AND SALE.BAGCOUNT=0 and sale.internet_sale=0'
        .' and may_back=0 and tick_back=0';

    /**
     * Колонки таблицы — порядок и названия legacy 1:1.
     *
     * `fio` => true помечает колонки, которые убирает галочка «Скрыть
     * ФИО» (в legacy их прятал клиент), `sortable` — колонку, по которой
     * сотрудник сортирует удостоверения.
     */
    public const COLUMNS = [
        ['key' => 'i', 'title' => '№', 'type' => 'number'],
        ['key' => 'av', 'title' => 'Автостанция', 'type' => 'string'],
        ['key' => 'lgot', 'title' => 'Льгота', 'type' => 'string'],
        ['key' => 'seria', 'title' => 'Серия', 'type' => 'string'],
        ['key' => 'n_ticket', 'title' => 'Номер билета', 'type' => 'string'],
        ['key' => 'atpname', 'title' => 'АТП', 'type' => 'string'],
        ['key' => 'raice', 'title' => 'Рейс', 'type' => 'string'],
        ['key' => 'statname', 'title' => 'Станция', 'type' => 'string'],
        ['key' => 'datestart', 'title' => 'Дата отправл.', 'type' => 'date'],
        ['key' => 'timestart', 'title' => 'Время отправл.', 'type' => 'time'],
        ['key' => 'n_place', 'title' => 'место', 'type' => 'number'],
        ['key' => 'price_place', 'title' => 'Стоимость проезда', 'type' => 'money'],
        ['key' => 'fio', 'title' => 'ФИО', 'type' => 'string', 'fio' => true],
        ['key' => 'udost', 'title' => 'Удостоверение', 'type' => 'string', 'fio' => true, 'sortable' => true],
        ['key' => 'comments', 'title' => 'Примечание', 'type' => 'string', 'fio' => true],
        ['key' => 'dob', 'title' => 'ДР', 'type' => 'date'],
        ['key' => 'age', 'title' => 'Возраст', 'type' => 'number'],
        ['key' => 'det', 'title' => 'ТИП', 'type' => 'string'],
        ['key' => 'money', 'title' => 'Стоимость билета', 'type' => 'money'],
    ];

    public function __construct(
        private readonly StationRegistry $stations,
        private readonly SiteSettings $settings,
        private readonly LgotExclusions $exclusions,
    ) {}

    public function meta(): array
    {
        // Отчётный период по умолчанию — прошлый месяц целиком (как в legacy).
        $prevMonth = strtotime('first day of last month');

        return [
            'title' => 'Нулевые билеты',
            'group' => 'Продажи/возвраты',
            // Своя страница в SPA: универсальный рендер отчётов этого не умеет.
            'view' => 'lgot',
            'permissions' => [
                'exclude' => 'Правка списков исключений (АТП и билеты)',
            ],
            'filters' => [
                // Вид рейса — то, ради чего в legacy было три отдельные
                // страницы. У каждого вида свои списки исключений.
                ['name' => 'kind', 'type' => 'select', 'label' => 'Рейсы',
                    'options' => LgotKind::selectOptions(), 'default' => LgotKind::DEFAULT],
                ['name' => 'date1', 'type' => 'date', 'label' => 'Дата начала',
                    'default' => date('Y-m-01', $prevMonth)],
                ['name' => 'date2', 'type' => 'date', 'label' => 'Дата окончания',
                    'default' => date('Y-m-t', $prevMonth)],
                /*
                 * Хозяйство и АТП — выбор С ПОИСКОМ и НЕСКОЛЬКИМИ
                 * значениями: станций два десятка, АТП бывает под сотню,
                 * а считают обычно по горстке. В legacy можно было взять
                 * только одно значение либо «все», и отчёт по трём
                 * перевозчикам собирали в три прогона. Пустой список =
                 * все (как «Все» в legacy).
                 */
                ['name' => 'stan', 'type' => 'multiselect', 'label' => 'Хозяйство',
                    'options' => $this->stationOptions(),
                    'all_label' => 'Все хозяйства',
                    /*
                     * По умолчанию — ПЕРВОЕ хозяйство, а не все: в legacy
                     * было так же, и неспроста — «все» это обход двух
                     * десятков баз станций по очереди, то есть минуты
                     * ожидания и занятый обработчик php-fpm.
                     */
                    'default' => array_slice(array_map(
                        'strval',
                        array_keys($this->stations->all()),
                    ), 0, 1)],
                ['name' => 'lgot', 'type' => 'select', 'label' => 'Тип билета',
                    'options' => [
                        ['value' => '-1', 'label' => '-Все-'],
                        ['value' => '0', 'label' => 'Проездной'],
                        ['value' => '2', 'label' => 'Социальный'],
                    ],
                    'default' => '-1'],
                // Список АТП зависит от периода и хозяйства — его отдаёт
                // отдельный запрос (reports/lgot/atps).
                ['name' => 'atp', 'type' => 'multiselect', 'label' => 'АТП',
                    'options' => [], 'all_label' => 'Все атп', 'default' => []],
                ['name' => 'iskl', 'type' => 'checkbox', 'label' => 'Исключить атп по договорам',
                    'default' => false],
                ['name' => 'iskl_tick', 'type' => 'checkbox', 'label' => 'Применять исключение билетов',
                    'default' => true],
            ],
        ];
    }

    /**
     * Опции «Хозяйство». Пункта «Все» среди них нет: выбор
     * множественный, и «ничего не выбрано» и значит «все» — отдельный
     * пункт с тем же смыслом только путал бы.
     */
    private function stationOptions(): array
    {
        $options = [];
        foreach ($this->stations->all() as $code => $station) {
            $options[] = ['value' => (string) $code, 'label' => $station['name']];
        }

        return $options;
    }

    public function run(array $filters): array
    {
        $in = $this->validated($filters);

        $errors = '';
        $rows = [];
        $totals = ['count' => 0, 'price_place' => 0.0, 'price_bag' => 0.0, 'strwant' => 0.0, 'money' => 0.0];
        $svod = [];

        // Список АТП по договорам приходит ПОЛЕМ запроса, а не из базы:
        // сотрудник правит его в том же окне и ждёт, что расчёт пойдёт по
        // набранному. Сохранение списка — отдельное действие (своё право).
        $exclAtp = $in['iskl'] ? $this->exclusions->parseAtpList($in['list_atp']) : [];
        $exclTickets = $this->exclusions->ticketKeys($in['kind']);

        $connectTimeout = $this->settings->stationConnectTimeout();
        $sql = $this->ticketsSql($in['kind'], $in['lgot']);

        foreach ($this->stationSubset($in['stan']) as $station) {
            try {
                $db = new StationConnection($station, $connectTimeout);
            } catch (\Throwable) {
                $errors .= 'Нет соединения с базой '.$station['name'].'<br>';

                continue;
            }

            try {
                $data = $db->select($sql, [$in['date1'], $in['date2']]);
            } catch (\Throwable) {
                $errors .= 'Ошибка работы с базой '.$station['name'].'<br>';

                continue;
            }

            foreach ($data as $value) {
                $atpname = (string) ($value['ATPNAME'] ?? '');

                // АТП по договору: его билеты из расчёта выпадают целиком.
                if ($exclAtp && in_array($atpname, $exclAtp, true)) {
                    continue;
                }

                if ($in['atp'] && ! in_array((int) $value['GO_ATPCOD'], $in['atp'], true)) {
                    continue;
                }

                $seria = trim((string) ($value['DOB_N_TICKET'] ?? ''));
                $number = trim((string) ($value['N_TICKET'] ?? ''));
                $tickKey = $seria.'||'.$number;
                $inIskl = isset($exclTickets[$tickKey]);

                if ($in['iskl_tick'] && $inIskl) {
                    continue;
                }

                $age = $this->age($value['DOB_PASS'] ?? null);
                $adult = $age >= self::ADULT_AGE;
                $money = (float) ($adult ? $value['PAYHARDALL'] : $value['PAYHARDCHD']);
                $strwant = $value['STRWANT_BACK'] ? 0.0 : (float) $value['STRWANT'];

                $rows[] = [
                    'i' => count($rows) + 1,
                    'av' => $station['name'],
                    'lgot' => $this->lgotName($value['LGOTCOD']),
                    'seria' => $seria,
                    'n_ticket' => $number,
                    'tick_key' => $tickKey,
                    // Билет в списке исключений, но исключение выключено —
                    // в таблице у него стоит пометка «искл.».
                    'in_iskl' => $inIskl,
                    'atpname' => $atpname,
                    'raice' => $value['RAICENAME'].'-'.$value['NAPNAME'],
                    'statname' => (string) ($value['STATNAME'] ?? ''),
                    'datestart' => date('d.m.Y', strtotime((string) $value['DATESTART'])),
                    'timestart' => date('H:i', strtotime((string) $value['TIMESTART'])),
                    'n_place' => $value['N_PLACE'],
                    'price_place' => (float) $value['PRICE_PLACE'],
                    'fio' => $value['FIO_PAS'].'  '.$value['NAME1_PASS'].'  '.$value['NAME2_PASS'],
                    'udost' => (string) ($value['NOM_UDOST'] ?? ''),
                    'comments' => (string) ($value['COMMENTS'] ?? ''),
                    'dob' => $value['DOB_PASS'] ? date('d.m.Y', strtotime((string) $value['DOB_PASS'])) : '',
                    'age' => $age,
                    'det' => $adult ? 'Взрослый' : 'Детский',
                    'money' => $money,
                ];

                $totals['count']++;
                $totals['price_place'] += (float) $value['PRICE_PLACE'];
                $totals['price_bag'] += (float) $value['PRICE_BAG'];
                $totals['strwant'] += $strwant;
                $totals['money'] += $money;

                // Сводка по станциям: в legacy она считалась, но на экран не
                // выводилась (её вывод там закомментирован). Оставляем в
                // ответе — считать заново по тем же строкам незачем.
                $name = $station['name'];
                $svod[$name] ??= ['statname' => $name, 'price_place' => 0.0, 'price_bag' => 0.0,
                    'bbag' => 0.0, 'strwant' => 0.0, 'itogo' => 0.0, 'money' => 0.0];
                $svod[$name]['price_place'] += (float) $value['PRICE_PLACE'];
                $svod[$name]['price_bag'] += (float) $value['PRICE_BAG'];
                $svod[$name]['bbag'] += (float) $value['PRICE_PLACE'] + (float) $value['PRICE_BAG'];
                $svod[$name]['strwant'] += $strwant;
                $svod[$name]['itogo'] += (float) $value['PRICE_PLACE'] + (float) $value['PRICE_BAG'] + $strwant;
                $svod[$name]['money'] += $money;
            }

            unset($db);
        }

        foreach ($totals as $key => $value) {
            $totals[$key] = $key === 'count' ? $value : round($value, 2);
        }

        return [
            'filters' => $filters,
            'errors' => $errors,
            'summary' => [
                ['label' => 'Билетов', 'value' => $totals['count']],
                ['label' => 'Стоимость билетов, руб.', 'value' => $totals['money']],
            ],
            'sections' => [[
                'id' => 'tickets',
                'title' => 'Нулевые билеты',
                'collapsed' => false,
                'columns' => self::COLUMNS,
                'rows' => $rows,
                'totals' => [
                    'atpname' => 'ИТОГО:',
                    'price_place' => $totals['price_place'],
                    'money' => $totals['money'],
                ],
            ]],

            // Сверх контракта — для страницы отчёта в АИС.
            'date1' => $in['date1'],
            'date2' => $in['date2'],
            'stan_name' => $this->stationTitle($in['stan']),
            'atp_name' => $this->atpTitle($in['atp'], $rows),
            'totals' => $totals,
            // Округление — чтобы в ответе не оставалось 771.9000000000002.
            'svod' => array_values(array_map(
                fn (array $row) => array_map(
                    fn ($value) => is_float($value) ? round($value, 2) : $value,
                    $row,
                ),
                $svod,
            )),
            'kind' => $in['kind'],
            'kind_title' => LgotKind::title($in['kind']),
            'list_atp' => $this->exclusions->atpListText($in['kind']),
            'list_tickets' => $this->exclusions->tickets($in['kind']),
        ];
    }

    /**
     * Список АТП, по которым за период есть нулевые билеты — отдельным
     * запросом, БЕЗ выборки самих билетов.
     *
     * В legacy селект АТП наполнялся из результата расчёта, поэтому в
     * подсказке отчёта стояло «при смене диапазона дат или автостанции
     * сначала расчёт делать по всем АТП для обновления списка АТП»: до
     * такого расчёта список был от прошлого периода. Здесь он спрашивается
     * сам при смене периода, хозяйства или типа билета.
     *
     * @return array{atps: array<string, string>, errors: string}
     */
    public function atpList(array $filters): array
    {
        $in = $this->validated($filters);

        $errors = '';
        $atps = [];
        $exclAtp = $in['iskl'] ? $this->exclusions->parseAtpList($in['list_atp']) : [];

        $connectTimeout = $this->settings->stationConnectTimeout();
        $sql = $this->atpsSql($in['kind'], $in['lgot']);

        foreach ($this->stationSubset($in['stan']) as $station) {
            try {
                $db = new StationConnection($station, $connectTimeout);
                $data = $db->select($sql, [$in['date1'], $in['date2']]);
            } catch (\Throwable) {
                $errors .= 'Нет соединения с базой '.$station['name'].'<br>';

                continue;
            }

            foreach ($data as $row) {
                $name = (string) ($row['ATPNAME'] ?? '');
                if ($exclAtp && in_array($name, $exclAtp, true)) {
                    continue;
                }
                $atps[(string) $row['GO_ATPCOD']] = $name;
            }

            unset($db);
        }

        // Порядок как в селекте legacy — по названию АТП.
        asort($atps, SORT_NATURAL | SORT_FLAG_CASE);

        return ['atps' => $atps, 'errors' => $errors];
    }

    /**
     * Проверка и приведение фильтров. Мусор на входе — ошибка проверки
     * (422), а не запись в журнал: по старым ссылкам ходят и роботы.
     *
     * @return array{kind: string, date1: string, date2: string, stan: list<string>, lgot: string, atp: list<int>, iskl: bool, iskl_tick: bool, list_atp: string}
     */
    private function validated(array $filters): array
    {
        // Пустая дата окончания = один день, как в legacy.
        if (($filters['date2'] ?? '') === '') {
            $filters['date2'] = $filters['date1'] ?? '';
        }

        // Хозяйство и АТП выбираются списком, но одиночное значение —
        // тоже валидный вход: так приходят старые ссылки и так удобнее
        // звать отчёт из консоли.
        $filters['stan'] = $this->toList($filters['stan'] ?? null);
        $filters['atp'] = $this->toList($filters['atp'] ?? null);

        $data = Validator::make($filters, [
            'kind' => ['nullable', 'string', 'in:'.implode(',', array_keys(LgotKind::ALL))],
            'date1' => ['required', 'date_format:Y-m-d'],
            'date2' => ['required', 'date_format:Y-m-d'],
            // Пустой список = все (в legacy это был пункт «Все»).
            'stan' => ['array', 'max:200'],
            'stan.*' => ['string', 'max:16'],
            'lgot' => ['nullable', 'in:-1,0,1,2'],
            // Код АТП — SMALLINT в базе станции; сверка идёт в PHP, но
            // границу держим и здесь.
            'atp' => ['array', 'max:500'],
            'atp.*' => ['integer', 'min:1', 'max:32767'],
            'iskl' => ['nullable', 'boolean'],
            'iskl_tick' => ['nullable', 'boolean'],
            'list_atp' => ['nullable', 'string', 'max:20000'],
        ], [
            'date1.required' => 'Укажите дату начала периода.',
            'date1.date_format' => 'Неверный формат дат.',
            'date2.date_format' => 'Неверный формат дат.',
        ])->validate();

        $known = $this->stations->all();
        $stan = [];
        foreach ($data['stan'] ?? [] as $code) {
            // 'all' и пустая строка — наследство одиночного селекта
            // legacy: оба значат «все хозяйства», то есть пустой список.
            if ($code === '' || $code === 'all') {
                $stan = [];

                break;
            }
            abort_if(
                ! isset($known[$code]),
                response()->json(['ok' => false, 'error' => 'Неизвестное хозяйство: '.$code], 422),
            );
            $stan[(string) $code] = (string) $code;
        }

        return [
            'kind' => LgotKind::normalize($data['kind'] ?? null),
            'date1' => $data['date1'],
            'date2' => $data['date2'],
            'stan' => array_values($stan),
            'lgot' => (string) ($data['lgot'] ?? '-1'),
            'atp' => array_values(array_unique(array_map('intval', $data['atp'] ?? []))),
            'iskl' => (bool) ($data['iskl'] ?? false),
            'iskl_tick' => (bool) ($data['iskl_tick'] ?? false),
            'list_atp' => (string) ($data['list_atp'] ?? ''),
        ];
    }

    /** Название льготы по коду — legacy 1:1 (1 и 2 оба «Социальный»). */
    private function lgotName(mixed $code): string
    {
        return match ((int) $code) {
            0 => 'Проездной',
            1, 2 => 'Социальный',
            default => 'Непонятно '.$code,
        };
    }

    /**
     * Условие по типу билета.
     *
     * «Социальный» — это LGOTCOD 1 ИЛИ 2: сам отчёт так и подписывает обе
     * строки. В legacy в запрос уходило `LGOTCOD=2`, а для Кром (5707)
     * код подменялся на 1 — то есть при выборе «Все хозяйства» социальные
     * билеты Кром в отчёт не попадали вовсе (проверено по базе Кром: там
     * встречаются только коды 0 и 1). Подмену по коду станции убрали,
     * условие покрывает оба кода.
     */
    private function lgotCondition(string $lgot): string
    {
        return match ($lgot) {
            '0' => ' AND SALE.LGOTCOD=0',
            '1', '2' => ' AND SALE.LGOTCOD IN (1,2)',
            default => '',
        };
    }

    /** Возраст на СЕГОДНЯ — как считала legacy-функция age(). */
    private function age(mixed $birthday): int
    {
        // Пустая дата рождения в legacy давала 1970 год и «Взрослый» —
        // поведение сохраняем, иначе суммы к возмещению разойдутся.
        $ts = strtotime((string) $birthday) ?: 0;
        $age = (int) date('Y') - (int) date('Y', $ts);

        if (date('md', $ts) > date('md')) {
            $age--;
        }

        return $age;
    }

    /**
     * Значение фильтра списком. Принимаем и массив, и одиночное значение
     * (одиночным его присылают старые ссылки), и строку через запятую.
     *
     * @return list<string>
     */
    private function toList(mixed $value): array
    {
        if ($value === null || $value === '') {
            return [];
        }

        if (! is_array($value)) {
            $value = explode(',', (string) $value);
        }

        return array_values(array_filter(
            array_map(fn ($item) => is_scalar($item) ? trim((string) $item) : '', $value),
            'strlen',
        ));
    }

    /**
     * Выбранные хозяйства. Пустой список = все (пункт «Все» в legacy);
     * неизвестные коды сюда не доходят — их отсеяла проверка.
     *
     * @param  list<string>  $stan
     * @return array<string, array>
     */
    private function stationSubset(array $stan): array
    {
        $all = $this->stations->all();

        if (! $stan) {
            return $all;
        }

        return array_intersect_key($all, array_flip($stan));
    }

    /** Хозяйства для шапки отчёта и имени файла выгрузки. */
    private function stationTitle(array $stan): string
    {
        if (! $stan) {
            return 'Все';
        }

        return implode(', ', array_map(
            fn (string $code) => $this->stations->name($code),
            $stan,
        ));
    }

    /**
     * АТП для шапки отчёта. Названия берём из выдачи — там они уже есть;
     * если строк нет (всё отфильтровано), остаются коды.
     *
     * @param  list<int>  $atp
     */
    private function atpTitle(array $atp, array $rows): string
    {
        if (! $atp) {
            return 'Все АТП';
        }

        $names = [];
        foreach ($rows as $row) {
            if ($row['atpname'] !== '') {
                $names[$row['atpname']] = true;
            }
        }

        return $names ? implode(', ', array_keys($names)) : 'АТП '.implode(', ', $atp);
    }

    /** Запрос билетов — набор колонок и джойны legacy 1:1. */
    private function ticketsSql(string $kind, string $lgot): string
    {
        $condition = self::TICKET_CONDITION.' and '.LgotKind::condition($kind);
        $lgotCondition = $this->lgotCondition($lgot);

        return "SELECT SALE.LGOTCOD, TARIFS2D.payhardall, TARIFS2D.payhardchd, SALE.COMMENTS, SALE.FIO_PAS,
                NAME1_PASS, NAME2_PASS, NOM_UDOST, SALE.GO_ATPCOD, SALE.DOB_PASS, PRICE_SPR AS skidka,
                SALE.N_TICKET, SALE.DOB_N_TICKET, SALE.n_PLACE, SALE.STRWANT_BACK, ATPLSTALL.ATPNAME,
                EVRYRAICE.RAICENAME, SALE.MAY_BACK, SALE.TICK_BACK, NAPLST.NAPNAME, EVRYRAICE.TIMESTART,
                SALE.DATESTART, SALE.STRWANT, SALE.PRICE_PLACE, SALE.PRICE_BAG, TRACES.STATNAME
            FROM SALE
            LEFT JOIN TRACES ON (TRACES.AWTCOD=SALE.AWTCOD AND TRACES.NAPCOD=SALE.NAPCOD AND TRACES.STATCOD=SALE.NOMSTAT)
            LEFT JOIN EVRYRAICE ON (EVRYRAICE.AWTCOD=SALE.AWTCOD AND EVRYRAICE.DATESTART=SALE.GO_DATESTART AND EVRYRAICE.NAPCOD=SALE.GO_NAPCOD AND EVRYRAICE.RAICECOD=SALE.GO_RAICECOD)
            LEFT JOIN NAPLST ON (SALE.GO_NAPCOD=NAPLST.NAPCOD AND SALE.AWTCOD=NAPLST.AWTCOD)
            LEFT JOIN ATPLSTALL ON (SALE.GO_ATPCOD=ATPLSTALL.ATPCOD)
            LEFT JOIN TARIFS2D ON (TARIFS2D.napcode=evryraice.napcod AND TARIFS2D.katcode=evryraice.nomkat AND sale.nomstat=TARIFS2D.statcode AND evryraice.awtcod=TARIFS2D.awtcode)
            WHERE SALE.TYPE_OPER=0 AND $condition AND SALE.GO_DATESTART BETWEEN ? AND ? $lgotCondition
            ORDER BY SALE.GO_DATESTART, EVRYRAICE.TIMESTART";
    }

    /**
     * Запрос списка АТП. Условия и джойн EVRYRAICE те же (на нём висит
     * MARTYPCOD=0), остальные джойны отчёта — LEFT и в условиях не
     * участвуют, то есть на состав АТП не влияют: лишние строки сюда не
     * тянем.
     */
    private function atpsSql(string $kind, string $lgot): string
    {
        $condition = self::TICKET_CONDITION.' and '.LgotKind::condition($kind);
        $lgotCondition = $this->lgotCondition($lgot);

        return "SELECT DISTINCT SALE.GO_ATPCOD, ATPLSTALL.ATPNAME
            FROM SALE
            LEFT JOIN EVRYRAICE ON (EVRYRAICE.AWTCOD=SALE.AWTCOD AND EVRYRAICE.DATESTART=SALE.GO_DATESTART AND EVRYRAICE.NAPCOD=SALE.GO_NAPCOD AND EVRYRAICE.RAICECOD=SALE.GO_RAICECOD)
            LEFT JOIN ATPLSTALL ON (SALE.GO_ATPCOD=ATPLSTALL.ATPCOD)
            WHERE SALE.TYPE_OPER=0 AND $condition AND SALE.GO_DATESTART BETWEEN ? AND ? $lgotCondition";
    }
}
