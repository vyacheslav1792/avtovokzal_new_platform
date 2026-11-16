<?php

namespace App\Exceptions;

/**
 * База продаж станции недоступна: до неё не дошло соединение.
 *
 * Это НЕ поломка приложения. У станции может пропасть интернет или
 * выключиться сервер (у Кром, например, интернета нет вовсе — 16.11.2026),
 * и такие отказы идут сотнями в час: в журнале sales-api за сутки
 * накопилось 2759 записей `connection rejected by remote interface` со
 * стектрейсами, а в журнале сайта — 2730 «Server Error» поверх них.
 *
 * Поэтому у отказа свой класс: сайту отдаём понятный текст и код
 * `station_unavailable` (он не логируется у backend как сбой), а в журнал
 * пишем одну короткую строку на станцию раз в несколько минут.
 */
class StationUnavailableException extends \RuntimeException
{
    public function __construct(
        public readonly int $awtcod,
        public readonly string $stationName,
        public readonly string $reason,
    ) {
        parent::__construct(
            'Автостанция «'.$stationName.'» сейчас недоступна. '
            .'Попробуйте позже или обратитесь в кассу.',
        );
    }

    public function errorCode(): string
    {
        return 'station_unavailable';
    }
}
