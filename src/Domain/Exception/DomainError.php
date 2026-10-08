<?php

declare(strict_types=1);

namespace HrPayroll\Domain\Exception;

use RuntimeException;

/**
 * Base class for expected failures that are safe to show to API clients.
 * The HTTP layer maps each subclass to a status code and a stable error code.
 */
abstract class DomainError extends RuntimeException
{
    abstract public function httpStatus(): int;

    abstract public function errorCode(): string;
}
