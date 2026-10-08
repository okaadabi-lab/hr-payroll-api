<?php

declare(strict_types=1);

namespace HrPayroll\Domain\Exception;

final class BadRequest extends DomainError
{
    public function __construct(string $message, private readonly string $errorCode = 'bad_request')
    {
        parent::__construct($message);
    }

    public function httpStatus(): int
    {
        return 400;
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }
}
