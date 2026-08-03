<?php

namespace App\Exceptions;

/** Прикладная ошибка базы продаж (резерв не найден, места заняты и т.п.). */
class BusterException extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $errorCode = 'buster_error',
    ) {
        parent::__construct($message);
    }
}
