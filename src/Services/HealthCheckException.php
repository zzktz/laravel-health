<?php

namespace Zzktz\LaravelHealth\Services;

use RuntimeException;
use Throwable;

class HealthCheckException extends RuntimeException
{
    private string $errorCode;

    public function __construct(string $errorCode, ?Throwable $previous = null)
    {
        $this->errorCode = $errorCode;
        parent::__construct($errorCode, 0, $previous);
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }
}
