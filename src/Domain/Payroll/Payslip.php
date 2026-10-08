<?php

declare(strict_types=1);

namespace HrPayroll\Domain\Payroll;

final class Payslip
{
    /**
     * @param list<PayslipLine> $lines
     */
    public function __construct(
        public readonly int $employeeId,
        public readonly string $period,
        public readonly array $lines,
    ) {
    }

    public function total(LineType $type): int
    {
        $sum = 0;
        foreach ($this->lines as $line) {
            if ($line->type === $type) {
                $sum += $line->amount;
            }
        }

        return $sum;
    }

    public function grossPay(): int
    {
        return $this->total(LineType::Earning);
    }

    public function totalDeductions(): int
    {
        return $this->total(LineType::Deduction);
    }

    public function netPay(): int
    {
        return $this->grossPay() - $this->totalDeductions();
    }

    public function employerCost(): int
    {
        return $this->grossPay() + $this->total(LineType::EmployerContribution);
    }

    public function line(string $code): ?PayslipLine
    {
        foreach ($this->lines as $line) {
            if ($line->code === $code) {
                return $line;
            }
        }

        return null;
    }
}
