<?php

namespace App\Support;

/**
 * Правила проверки вендорных кодов BUSTER (awtcod, napcod, raicecod,
 * statcod и прочие идентификаторы станционной базы).
 *
 * Зачем отдельное правило: значение вне диапазона поля Firebird встречает
 * арифметической ошибкой «-303 numeric overflow» — то есть ответом 500 и
 * девятью килобайтами стектрейса в журнале. А присылают такое постоянно:
 * боты и сканеры подставляют в параметры любые числа, да и старые ссылки
 * сайта несут параметры, которых у нас уже нет. Мусор на входе не должен
 * ни доходить до станции, ни оставлять в журнале ошибок след — поэтому
 * границы проверяются сразу.
 *
 * ПРЕДЕЛ — SMALLINT, а НЕ INTEGER. Прежний комментарий здесь утверждал
 * «в базе станции это INTEGER», и предел стоял 2147483647 — то есть дыра
 * была в 65 тысяч раз шире нужного. Типы проверены по самой базе
 * (RDB$FIELDS), все перечисленные поля — `SHORT`, 2 байта:
 *   EVRYRAICE: AWTCOD, NAPCOD, RAICECOD, NOMKAT
 *   RAICELST:  AWTCOD, NAPCOD, RAICECOD
 *   TARIFS2D:  AWTCODE, NAPCODE, STATCODE, KATCODE
 *   TRACES:    AWTCOD, NAPCOD, STATCOD
 *   NAPLST:    AWTCOD, NAPCOD
 *   SALE:      N_PLACE, KATPAS, DOCUMCOD_PASS, GRAGD_ID, TYPE_TICK, WRKCOD
 * Ловилось на проде 17.11.2026: обходчик открыл старую ссылку тарифа с
 * `raicecod=904407066` — проверку int32 она прошла, а станция ответила
 * «-303 numeric overflow», и покупателю ушло «Server Error».
 *
 * Ноль и отрицательные тоже отсекаем: кодов таких не бывает.
 */
final class VendorCode
{
    /**
     * Предел SMALLINT у Firebird — дальше начинается «numeric overflow».
     * Номера бланков (N_TICKET, DOB_N_TICKET) под это правило НЕ попадают:
     * в базе они VARCHAR и проверяются как строки.
     */
    public const MAX = 32767;

    /** @return array<int, string> */
    public static function rules(bool $required = true): array
    {
        return [$required ? 'required' : 'nullable', 'integer', 'min:1', 'max:'.self::MAX];
    }

    /**
     * Границы для необязательных числовых полей, у которых ноль осмыслен
     * (количество багажа, код документа из справочника и т.п.).
     *
     * @return array<int, string>
     */
    public static function boundsFrom(int $min = 0): array
    {
        return ['integer', 'min:'.$min, 'max:'.self::MAX];
    }
}
