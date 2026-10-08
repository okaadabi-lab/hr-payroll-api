<?php

declare(strict_types=1);

namespace HrPayroll\Domain;

enum PeriodStatus: string
{
    case Draft = 'draft';
    case Calculated = 'calculated';
    case Approved = 'approved';
    case Paid = 'paid';

    public function canCalculate(): bool
    {
        return $this === self::Draft || $this === self::Calculated;
    }

    /**
     * Approved and paid periods are frozen: attendance, overtime and holidays
     * inside them can no longer change.
     */
    public function isLocked(): bool
    {
        return $this === self::Approved || $this === self::Paid;
    }
}
