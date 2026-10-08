<?php

declare(strict_types=1);

namespace HrPayroll\Http;

use DateTimeImmutable;
use HrPayroll\Domain\Exception\ValidationFailed;

/**
 * Collects field errors while reading typed values from a decoded request body,
 * so a client gets every problem in one 422 response instead of one at a time.
 */
final class Input
{
    public const MAX_MONEY = 1_000_000_000_000;

    /** @var list<array{field: string, message: string}> */
    private array $errors = [];

    /**
     * @param array<string, mixed> $data
     */
    public function __construct(private readonly array $data)
    {
    }

    public function has(string $field): bool
    {
        return array_key_exists($field, $this->data);
    }

    public function isNull(string $field): bool
    {
        return $this->has($field) && $this->data[$field] === null;
    }

    public function string(string $field, int $max = 255, bool $required = true, int $min = 1): ?string
    {
        if (!$this->present($field, $required)) {
            return null;
        }
        $value = $this->data[$field];
        if (!is_string($value)) {
            return $this->fail($field, 'Must be a string.');
        }
        $value = trim($value);
        $length = mb_strlen($value);
        if ($length < $min || $length > $max) {
            return $this->fail($field, "Must be between {$min} and {$max} characters.");
        }

        return $value;
    }

    public function email(string $field, bool $required = true): ?string
    {
        $value = $this->string($field, 190, $required);
        if ($value !== null && filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
            return $this->fail($field, 'Must be a valid email address.');
        }

        return $value === null ? null : strtolower($value);
    }

    public function int(string $field, int $min = 1, int $max = PHP_INT_MAX, bool $required = true): ?int
    {
        if (!$this->present($field, $required)) {
            return null;
        }
        $value = $this->data[$field];
        if (!is_int($value) || $value < $min || $value > $max) {
            return $this->fail($field, "Must be an integer between {$min} and {$max}.");
        }

        return $value;
    }

    public function money(string $field, bool $required = true): ?int
    {
        return $this->int($field, 0, self::MAX_MONEY, $required);
    }

    public function date(string $field, bool $required = true): ?string
    {
        if (!$this->present($field, $required)) {
            return null;
        }
        $value = $this->data[$field];

        if (!is_string($value) || !self::isDate($value)) {
            return $this->fail($field, 'Must be a date in YYYY-MM-DD format.');
        }

        return $value;
    }

    public function time(string $field, bool $required = true): ?string
    {
        if (!$this->present($field, $required) || $this->isNull($field)) {
            return null;
        }
        $value = $this->data[$field];
        if (!is_string($value) || preg_match('/^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/', $value) !== 1) {
            return $this->fail($field, 'Must be a time in HH:MM format.');
        }

        return strlen($value) === 5 ? $value . ':00' : $value;
    }

    /**
     * @param list<string> $allowed
     */
    public function oneOf(string $field, array $allowed, bool $required = true): ?string
    {
        if (!$this->present($field, $required)) {
            return null;
        }
        $value = $this->data[$field];
        if (!is_string($value) || !in_array($value, $allowed, true)) {
            return $this->fail($field, 'Must be one of: ' . implode(', ', $allowed) . '.');
        }

        return $value;
    }

    /**
     * Hours as a JSON number in steps of 0.5, returned as whole minutes.
     */
    public function hoursAsMinutes(string $field): ?int
    {
        if (!$this->present($field, true)) {
            return null;
        }
        $value = $this->data[$field];
        if (!is_int($value) && !is_float($value)) {
            return $this->fail($field, 'Must be a number of hours.');
        }
        $halfHours = $value * 2;
        if ($value <= 0 || $halfHours != floor($halfHours)) {
            return $this->fail($field, 'Must be a positive number of hours in steps of 0.5.');
        }

        return (int) ($halfHours * 30);
    }

    /**
     * @return list<int>
     */
    public function idList(string $field, int $maxItems): array
    {
        if (!$this->present($field, true)) {
            return [];
        }
        $value = $this->data[$field];
        if (!is_array($value) || !array_is_list($value) || $value === [] || count($value) > $maxItems) {
            $this->fail($field, "Must be a non-empty array of at most {$maxItems} ids.");

            return [];
        }
        foreach ($value as $id) {
            if (!is_int($id) || $id < 1) {
                $this->fail($field, 'Every id must be a positive integer.');

                return [];
            }
        }

        return array_values(array_unique($value));
    }

    public function addError(string $field, string $message): void
    {
        $this->errors[] = ['field' => $field, 'message' => $message];
    }

    public function validate(): void
    {
        if ($this->errors !== []) {
            throw new ValidationFailed($this->errors);
        }
    }

    public static function isDate(string $value): bool
    {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $parsed !== false && $parsed->format('Y-m-d') === $value;
    }

    private function present(string $field, bool $required): bool
    {
        if ($this->has($field)) {
            return true;
        }
        if ($required) {
            $this->addError($field, 'This field is required.');
        }

        return false;
    }

    private function fail(string $field, string $message): null
    {
        $this->addError($field, $message);

        return null;
    }
}
