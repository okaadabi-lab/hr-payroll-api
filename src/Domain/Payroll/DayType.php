<?php

declare(strict_types=1);

namespace HrPayroll\Domain\Payroll;

enum DayType: string
{
    case Workday = 'workday';
    case RestDay = 'rest_day';
    case PublicHoliday = 'public_holiday';

    public function isWorkday(): bool
    {
        return $this === self::Workday;
    }
}
