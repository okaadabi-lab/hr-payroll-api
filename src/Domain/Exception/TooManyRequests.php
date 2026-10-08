<?php

declare(strict_types=1);

namespace HrPayroll\Domain\Exception;

final class TooManyRequests extends DomainError
{
    public function __construct(string $message, private readonly int $retryAfterSeconds)
    {
        parent::__construct($message);
    }

    public function retryAfterSeconds(): int
    {
        return $this->retryAfterSeconds;
    }

    public function httpStatus(): int
    {
        return 429;
    }

    public function errorCode(): string
    {
        return 'too_many_requests';
    }
}
