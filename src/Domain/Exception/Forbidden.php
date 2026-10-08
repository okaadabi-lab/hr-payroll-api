<?php

declare(strict_types=1);

namespace HrPayroll\Domain\Exception;

final class Forbidden extends DomainError
{
    public function __construct(string $message, private readonly string $errorCode = 'forbidden')
    {
        parent::__construct($message);
    }

    public function httpStatus(): int
    {
        return 403;
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }
}
