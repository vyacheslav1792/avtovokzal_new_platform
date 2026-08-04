<?php

namespace App\Support;

/**
 * ФИО в билете: верхний регистр и **Ё → Е**.
 *
 * Буква Ё ломает вендорный софт станции, поэтому заменяется всегда —
 * и в заказе, и в сохранённом пассажире кабинета (иначе подставленное
 * из профиля имя отличалось бы от того, что попало в билет).
 */
class PassengerName
{
    public static function normalize(?string $value): string
    {
        return str_replace('Ё', 'Е', mb_strtoupper(trim((string) $value)));
    }
}
