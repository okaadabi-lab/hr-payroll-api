<?php

declare(strict_types=1);

namespace HrPayroll\Domain\Exception;

final class Conflict extends DomainError
{
    public function __construct(string $message, private readonly string $errorCode = 'conflict')
    {
        parent::__construct($message);
    }

    public function httpStatus(): int
    {
        return 409;
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }
}
