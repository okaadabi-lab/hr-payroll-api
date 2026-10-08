<?php

declare(strict_types=1);

namespace HrPayroll\Domain\Payroll;

final class OvertimeEntry
{
    public function __construct(
        public readonly string $date,
        public readonly int $minutes,
    ) {
    }
}
