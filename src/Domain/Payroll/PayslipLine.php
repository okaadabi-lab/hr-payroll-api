<?php

declare(strict_types=1);

namespace HrPayroll\Domain\Payroll;

final class PayslipLine
{
    public function __construct(
        public readonly LineType $type,
        public readonly string $code,
        public readonly string $label,
        public readonly int $amount,
        public readonly string $basis = '',
    ) {
    }
}
