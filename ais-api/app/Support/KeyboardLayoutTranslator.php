<?php

namespace App\Support;

/**
 * Перевод строки между раскладками QWERTY и ЙЦУКЕН.
 *
 * Нужен оценке стойкости пароля: «gfhjkm» — это набранное вслепую слово
 * «пароль», и без перевода раскладки словарь его не узнаёт.
 */
class KeyboardLayoutTranslator
{
    private const LATIN = 'qwertyuiop[]asdfghjkl;\'zxcvbnm,./`QWERTYUIOP{}ASDFGHJKL:"ZXCVBNM<>?~';

    private const CYRILLIC = 'йцукенгшщзхъфывапролджэячсмитьбю.ёЙЦУКЕНГШЩЗХЪФЫВАПРОЛДЖЭЯЧСМИТЬБЮ,Ё';

    /** Строка, набранная в латинской раскладке, прочитанная как русская. */
    public static function latinToCyrillic(string $value): string
    {
        return self::translate($value, self::LATIN, self::CYRILLIC);
    }

    /** Строка, набранная в русской раскладке, прочитанная как латинская. */
    public static function cyrillicToLatin(string $value): string
    {
        return self::translate($value, self::CYRILLIC, self::LATIN);
    }

    private static function translate(string $value, string $from, string $to): string
    {
        $map = array_combine(mb_str_split($from), mb_str_split($to));

        $result = '';
        foreach (mb_str_split($value) as $char) {
            $result .= $map[$char] ?? $char;
        }

        return $result;
    }
}
