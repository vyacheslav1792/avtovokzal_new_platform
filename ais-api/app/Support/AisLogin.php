<?php

namespace App\Support;

/**
 * Логин сотрудника АИС. Состав символов НЕ ограничиваем (русские буквы,
 * точки, дефисы — как кому удобно), но вход нечувствителен к регистру и
 * к пробелам по краям: «Иванов » и «иванов» — один и тот же человек.
 */
class AisLogin
{
    /** Логин в том виде, в котором он хранится и показывается. */
    public static function clean(string $login): string
    {
        // \p{Z} вместо \s: неразрывный пробел попадает в логин при
        // копировании из Word, на глаз от обычного не отличается, а
        // \s в utf-режиме его не ловит.
        $login = preg_replace('/[\p{Z}\s]+/u', ' ', $login) ?? $login;

        return trim($login);
    }

    /** Ключ поиска: тот же логин в нижнем регистре. */
    public static function normalize(string $login): string
    {
        return mb_strtolower(static::clean($login));
    }
}
