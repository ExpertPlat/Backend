<?php

namespace App\Exceptions;

use RuntimeException;

class PrivacyException extends RuntimeException
{
    public function __construct(public readonly string $errorCode, string $message, public readonly int $httpStatus = 409)
    {
        parent::__construct($message);
    }
}
