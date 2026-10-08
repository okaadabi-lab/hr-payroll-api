<?php

declare(strict_types=1);

namespace HrPayroll\Domain\Exception;

final class ValidationFailed extends DomainError
{
    /**
     * @param list<array{field: string, message: string}> $errors
     */
    public function __construct(private readonly array $errors, string $message = 'The request contains invalid data.')
    {
        parent::__construct($message);
    }

    public static function single(string $field, string $message): self
    {
        return new self([['field' => $field, 'message' => $message]], $message);
    }

    /**
     * @return list<array{field: string, message: string}>
     */
    public function errors(): array
    {
        return $this->errors;
    }

    public function httpStatus(): int
    {
        return 422;
    }

    public function errorCode(): string
    {
        return 'validation_failed';
    }
}
