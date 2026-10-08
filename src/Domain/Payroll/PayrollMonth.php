<?php

declare(strict_types=1);

namespace HrPayroll\Domain\Payroll;

use DateTimeImmutable;
use InvalidArgumentException;

final class PayrollMonth
{
    private function __construct(private readonly DateTimeImmutable $firstDay)
    {
    }

    public static function fromString(string $value): self
    {
        if (preg_match('/^(\d{4})-(0[1-9]|1[0-2])$/', $value) !== 1) {
            throw new InvalidArgumentException('Period must use the format YYYY-MM.');
        }
        $firstDay = DateTimeImmutable::createFromFormat('!Y-m-d', $value . '-01');
        if ($firstDay === false) {
            throw new InvalidArgumentException('Period must use the format YYYY-MM.');
        }

        return new self($firstDay);
    }

    public function firstDay(): string
    {
        return $this->firstDay->format('Y-m-d');
    }

    public function lastDay(): string
    {
        return $this->firstDay->modify('last day of this month')->format('Y-m-d');
    }

    /**
     * @return list<string> every date of the month as Y-m-d
     */
    public function dates(): array
    {
        $dates = [];
        $days = (int) $this->firstDay->format('t');
        for ($day = 0; $day < $days; $day++) {
            $dates[] = $this->firstDay->modify("+{$day} days")->format('Y-m-d');
        }

        return $dates;
    }

    public function contains(string $date): bool
    {
        return $date >= $this->firstDay() && $date <= $this->lastDay();
    }

    public function toString(): string
    {
        return $this->firstDay->format('Y-m');
    }
}
