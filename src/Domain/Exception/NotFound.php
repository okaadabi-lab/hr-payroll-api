<?php

declare(strict_types=1);

namespace HrPayroll\Domain\Exception;

final class NotFound extends DomainError
{
    public function __construct(string $message, private readonly string $errorCode = 'not_found')
    {
        parent::__construct($message);
    }

    public function httpStatus(): int
    {
        return 404;
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }
}
