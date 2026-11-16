<?php

namespace App\Services;

class SalesApiException extends \RuntimeException
{
    /**
     * Отказы, которые НЕ являются сбоем: станция ответила «так нельзя», и
     * сайт показывает покупателю обычное сообщение.
     *
     * Их не нужно писать в журнал со стектрейсом: поисковые роботы ходят
     * по старым ссылкам расписания с прошедшими датами, и на проде это
     * давало 4601 запись ERROR за сутки — журнал backend разросся до 68 МБ,
     * а настоящие сбои в этой массе было не разглядеть (16.11.2026).
     */
    private const EXPECTED = [
        // Дата старше 48 часов: «Данные за этот период более недоступны».
        'date_out_of_range',
        'trip_not_found',
        'station_not_found',
        // Продажа или возврат по станции выключены в АИС — это решение
        // администратора, а не поломка.
        'station_sales_disabled',
        'station_refunds_disabled',
        /*
         * До базы станции нет связи (у Кром, например, нет интернета).
         * Такой отказ уже записан у sales-api — одной строкой на станцию
         * раз в пять минут; дублировать его здесь стектрейсом незачем, а
         * шло их по 2730 в сутки.
         */
        'station_unavailable',
    ];

    public function __construct(
        string $message,
        public readonly string $errorCode = 'sales_api_error',
    ) {
        parent::__construct($message);
    }

    /**
     * Штатный отказ, а не сбой. Конфликты продажи (`already_sold`,
     * `sale_in_progress`, `concurrent_sale`) сюда НЕ входят сознательно:
     * они про деньги покупателя, и их надо видеть в журнале.
     */
    public function isExpected(): bool
    {
        return in_array($this->errorCode, self::EXPECTED, true);
    }
}
