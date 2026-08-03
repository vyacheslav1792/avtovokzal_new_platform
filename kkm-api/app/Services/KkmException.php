<?php

namespace App\Services;

class KkmException extends \RuntimeException
{
    public function __construct(string $message, public readonly string $errorCode = 'kkm_error')
    {
        parent::__construct($message);
    }
}
