<?php

declare(strict_types=1);

namespace HrPayroll\Domain\Exception;

final class Unauthenticated extends DomainError
{
    public function __construct(string $message, private readonly string $errorCode = 'unauthenticated')
    {
        parent::__construct($message);
    }

    public function httpStatus(): int
    {
        return 401;
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }
}
