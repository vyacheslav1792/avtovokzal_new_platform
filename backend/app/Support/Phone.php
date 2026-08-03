<?php

namespace App\Support;

/** Нормализация российских номеров: 8/+7/9… → +7XXXXXXXXXX. */
class Phone
{
    /** @return string|null +7XXXXXXXXXX либо null, если номер не похож на российский */
    public static function normalize(?string $phone): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $phone);

        if (strlen($digits) === 11 && ($digits[0] === '8' || $digits[0] === '7')) {
            $digits = '7'.substr($digits, 1);
        } elseif (strlen($digits) === 10 && $digits[0] === '9') {
            $digits = '7'.$digits;
        }

        return strlen($digits) === 11 && $digits[0] === '7' ? '+'.$digits : null;
    }
}
