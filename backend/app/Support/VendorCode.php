<?php

namespace App\Support;

/**
 * Правила проверки вендорных кодов BUSTER (awtcod, napcod, raicecod,
 * statcod и прочие идентификаторы станционной базы).
 *
 * Зачем отдельное правило: в базе станции это INTEGER, и значение вне
 * int32 Firebird встречает арифметической ошибкой «-303 numeric overflow»
 * — то есть ответом 500 и девятью килобайтами стектрейса в журнале. А
 * присылают такое постоянно: боты и сканеры подставляют в параметры
 * любые числа. Мусор на входе не должен ни доходить до станции, ни
 * оставлять в журнале ошибок след — поэтому границы проверяются сразу.
 *
 * Ноль и отрицательные тоже отсекаем: кодов таких не бывает.
 */
final class VendorCode
{
    /** Предел INTEGER у Firebird — дальше начинается «numeric overflow». */
    public const MAX = 2147483647;

    /** @return array<int, string> */
    public static function rules(bool $required = true): array
    {
        return [$required ? "required" : "nullable", "integer", "min:1", "max:".self::MAX];
    }
}
