<?php
declare(strict_types=1);

namespace C0m\AccountCenter;

use RuntimeException;

final class AccountCenterException extends RuntimeException
{
    public function __construct(
        string $message,
        private readonly string $errorCode,
        private readonly int $httpStatus = 0
    ) {
        parent::__construct($message);
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    public function httpStatus(): int
    {
        return $this->httpStatus;
    }
}
