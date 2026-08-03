<?php

namespace App\Services;

/**
 * Разбор и валидация документа пассажира.
 *
 * Повторяет проверку вендорной процедуры BUSTER KS_CONTROL_DATA_PAS,
 * которую KS_SALE_PLACES выполняет первой при оформлении билета.
 * Здесь она выполняется ДО оплаты: иначе пользователь заплатит и только
 * потом получит отказ станции.
 *
 * Коды: 21 паспорт РФ, 22 загранпаспорт РФ, 3 свидетельство о рождении,
 * 15 военный билет, 26 паспорт моряка, 10 документ иностранца.
 * Гражданство 185 = Россия (COUNTRIES_TABLE).
 */
class PassengerDocument
{
    public const RUSSIA = 185;

    /** Российские документы: недопустимы при другом гражданстве (правило процедуры). */
    private const RUSSIAN_ONLY_DOCS = [15, 21, 22];

    private const ROMAN = ['I', 'X', 'L', 'V', 'C', 'D', 'M'];

    /**
     * Разобрать ввод «серия и номер одной строкой» по типу документа.
     *
     * @return array{series: string, number: string}
     */
    public static function parse(int $docType, string $raw): array
    {
        $clean = mb_strtoupper(str_replace(['№', ' '], '', trim($raw)));

        switch ($docType) {
            case 21: // паспорт РФ: 4 цифры + 6 цифр
                $digits = str_replace('-', '', $clean);

                return ['series' => mb_substr($digits, 0, 4), 'number' => mb_substr($digits, 4, 15)];

            case 22: // загранпаспорт: 2 цифры + 7 цифр
                $digits = str_replace('-', '', $clean);

                return ['series' => mb_substr($digits, 0, 2), 'number' => mb_substr($digits, 2, 15)];

            case 3: // свидетельство о рождении: римские + 2 кириллицы, номер 6 цифр
                if (preg_match('/^(.*?)(\d{1,15})$/u', $clean, $m)) {
                    return ['series' => trim($m[1], '-'), 'number' => $m[2]];
                }

                return ['series' => $clean, 'number' => ''];

            case 15: // военный билет: 2 кириллицы + 7 цифр
            case 26: // паспорт моряка: 2 римские + 7 цифр
                if (preg_match('/^(.*?)(\d{1,15})$/u', $clean, $m)) {
                    return ['series' => str_replace('-', '', $m[1]), 'number' => $m[2]];
                }

                return ['series' => str_replace('-', '', $clean), 'number' => ''];

            case 10: // документ иностранца: как legacy — первые 2 знака в серию
                $flat = str_replace('-', '', $clean);

                return ['series' => mb_substr($flat, 0, 2), 'number' => mb_substr($flat, 2, 15)];

            default: // прочие документы: формат станцией не проверяется
                $flat = str_replace('-', '', $clean);
                $series = mb_substr($flat, 0, 4);
                $number = mb_substr($flat, 4, 15);
                // legacy добивает номер нулями до общей длины 9
                while (mb_strlen($series.$number) < 9) {
                    $number .= '0';
                }

                return ['series' => $series, 'number' => $number];
        }
    }

    /**
     * Проверка по правилам KS_CONTROL_DATA_PAS.
     *
     * @return string|null  текст ошибки (по-русски, для человека) или null
     */
    public static function validate(int $docType, int $citizenship, string $series, string $number): ?string
    {
        $isRussian = $citizenship === self::RUSSIA;

        // Несовместимость документа и гражданства
        if ($docType === 10 && $isRussian) {
            return 'Выбран документ иностранного гражданина, а гражданство — Россия. Выберите паспорт РФ или другой российский документ.';
        }
        if (in_array($docType, self::RUSSIAN_ONLY_DOCS, true) && ! $isRussian) {
            return 'Этот документ выдаётся только гражданам России. Проверьте гражданство или выберите другой документ.';
        }

        switch ($docType) {
            case 21:
                if (! preg_match('/^\d{4}$/', $series) || ! preg_match('/^\d{6}$/', $number)) {
                    return 'Серия и номер паспорта РФ — это 4 цифры серии и 6 цифр номера, например: 4444 555555.';
                }
                break;

            case 22:
                if (! preg_match('/^\d{2}$/', $series) || ! preg_match('/^\d{7}$/', $number)) {
                    return 'Серия и номер загранпаспорта — это 2 цифры серии и 7 цифр номера, например: 75 1234567.';
                }
                break;

            case 3:
                if ($isRussian) {
                    $ser = str_replace('-', '', $series);
                    $len = mb_strlen($ser);
                    // римские цифры + 2 кириллические буквы (например IVАБ)
                    $romanPart = $len >= 3 ? mb_substr($ser, 0, $len - 2) : '';
                    $cyrPart = mb_substr($ser, -2);
                    $romanOk = $romanPart !== '' && $len <= 12
                        && preg_match('/^['.implode('', self::ROMAN).']+$/u', $romanPart);
                    $cyrOk = preg_match('/^[А-Я]{2}$/u', $cyrPart);
                    if (! $romanOk || ! $cyrOk || ! preg_match('/^\d{6}$/', $number)) {
                        return 'Серия и номер свидетельства о рождении: римские цифры, две русские буквы и 6 цифр номера, например: IV-АБ 123456.';
                    }
                }
                break;

            case 15:
                if (! preg_match('/^[А-Я]{2}$/u', $series) || ! preg_match('/^\d{7}$/', $number)) {
                    return 'Серия и номер военного билета: две русские буквы и 7 цифр номера, например: АБ 1234567.';
                }
                break;

            case 26:
                if (! preg_match('/^['.implode('', self::ROMAN).']{2}$/u', $series) || ! preg_match('/^\d{7}$/', $number)) {
                    return 'Серия и номер паспорта моряка: две римские цифры и 7 цифр номера, например: XV 1234567.';
                }
                break;
        }

        return null;
    }
}
