<?php

declare(strict_types=1);

namespace HrPayroll\Domain\Payroll;

use InvalidArgumentException;

final class BpjsRate
{
    public function __construct(
        public readonly BpjsProgram $program,
        public readonly int $employeeBasisPoints,
        public readonly int $employerBasisPoints,
        public readonly ?int $wageCap,
    ) {
        if ($employeeBasisPoints < 0 || $employerBasisPoints < 0) {
            throw new InvalidArgumentException('BPJS rates cannot be negative.');
        }
        if ($wageCap !== null && $wageCap <= 0) {
            throw new InvalidArgumentException('BPJS wage cap must be positive.');
        }
    }

    public function contributionBase(int $monthlyWage): int
    {
        return $this->wageCap === null ? $monthlyWage : min($monthlyWage, $this->wageCap);
    }

    public function employeeShare(int $monthlyWage): int
    {
        return Rounding::basisPoints($this->contributionBase($monthlyWage), $this->employeeBasisPoints);
    }

    public function employerShare(int $monthlyWage): int
    {
        return Rounding::basisPoints($this->contributionBase($monthlyWage), $this->employerBasisPoints);
    }
}
