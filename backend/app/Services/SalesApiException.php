<?php

namespace App\Services;

class SalesApiException extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $errorCode = 'sales_api_error',
    ) {
        parent::__construct($message);
    }
}
