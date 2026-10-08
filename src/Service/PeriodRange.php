<?php

declare(strict_types=1);

namespace HrPayroll\Service;

use HrPayroll\Domain\CalendarDate;
use HrPayroll\Domain\Exception\ValidationFailed;
use HrPayroll\Domain\Payroll\PayrollMonth;
use HrPayroll\Query\ListQuery;
use InvalidArgumentException;

/**
 * Turns the period / from / to list filters into concrete date bounds.
 */
final class PeriodRange
{
    /**
     * @return array{from?: string, to?: string}
     */
    public static function fromQuery(ListQuery $query): array
    {
        $range = [];
        $period = $query->filter('period');
        if ($period !== null) {
            try {
                $month = PayrollMonth::fromString($period);
            } catch (InvalidArgumentException $e) {
                throw ValidationFailed::single('period', $e->getMessage());
            }
            $range = ['from' => $month->firstDay(), 'to' => $month->lastDay()];
        }
        foreach (['from', 'to'] as $bound) {
            $value = $query->filter($bound);
            if ($value === null) {
                continue;
            }
            if (!CalendarDate::isValid($value)) {
                throw ValidationFailed::single($bound, 'Must be a date in YYYY-MM-DD format.');
            }
            $range[$bound] = $value;
        }

        return $range;
    }
}
