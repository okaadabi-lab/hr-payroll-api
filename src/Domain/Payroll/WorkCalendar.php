<?php

declare(strict_types=1);

namespace HrPayroll\Domain\Payroll;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Five-day work week (Monday to Friday) with Saturday and Sunday as weekly
 * rest days, plus the public holidays passed in.
 */
final class WorkCalendar
{
    /** @var array<string, true> */
    private readonly array $holidays;

    /**
     * @param list<string> $holidayDates Y-m-d
     */
    public function __construct(array $holidayDates)
    {
        $holidays = [];
        foreach ($holidayDates as $date) {
            $holidays[$date] = true;
        }
        $this->holidays = $holidays;
    }

    public function dayType(string $date): DayType
    {
        if (isset($this->holidays[$date])) {
            return DayType::PublicHoliday;
        }

        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if ($parsed === false || $parsed->format('Y-m-d') !== $date) {
            throw new InvalidArgumentException("Invalid date: {$date}");
        }

        return (int) $parsed->format('N') >= 6 ? DayType::RestDay : DayType::Workday;
    }

    public function workingDaysIn(PayrollMonth $month): int
    {
        $count = 0;
        foreach ($month->dates() as $date) {
            if ($this->dayType($date)->isWorkday()) {
                $count++;
            }
        }

        return $count;
    }
}
