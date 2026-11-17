<?php

namespace App\Exceptions;

/** Прикладная ошибка базы продаж (резерв не найден, места заняты и т.п.). */
class BusterException extends \RuntimeException
{
    /**
     * @param  array<string, mixed>  $details  машиночитаемые подробности отказа
     *                                         (например, результат по каждому бланку
     *                                         возврата) — по ним вызывающая сторона
     *                                         понимает, ЧТО именно отклонено, а не
     *                                         только «возврат не прошёл».
     */
    public function __construct(
        string $message,
        public readonly string $errorCode = 'buster_error',
        public readonly array $details = [],
    ) {
        parent::__construct($message);
    }
}
