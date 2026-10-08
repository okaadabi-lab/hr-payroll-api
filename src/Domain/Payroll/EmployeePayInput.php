<?php

declare(strict_types=1);

namespace HrPayroll\Domain\Payroll;

use InvalidArgumentException;

final class EmployeePayInput
{
    /**
     * @param list<string>        $absentDates     dates with an unexcused absence (Y-m-d)
     * @param list<OvertimeEntry> $approvedOvertime
     */
    public function __construct(
        public readonly int $employeeId,
        public readonly int $baseSalary,
        public readonly int $fixedAllowances,
        public readonly array $absentDates = [],
        public readonly array $approvedOvertime = [],
    ) {
        if ($baseSalary < 0 || $fixedAllowances < 0) {
            throw new InvalidArgumentException('Salary components cannot be negative.');
        }
    }

    public function monthlyWage(): int
    {
        return $this->baseSalary + $this->fixedAllowances;
    }
}
