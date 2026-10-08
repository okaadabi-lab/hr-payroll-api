<?php

declare(strict_types=1);

namespace HrPayroll\Domain\Payroll;

use DateTimeImmutable;

/**
 * Validates a new overtime request against PP 35/2021 limits.
 *
 * Workday overtime is capped at 4 hours per day and 18 hours per ISO week
 * (Monday to Sunday). Overtime on rest days and public holidays is not counted
 * towards those caps - the regulation pays it up to the 12th hour on a five-day
 * week, which would be impossible under a 4-hour daily cap - so it is limited
 * to 12 hours per day instead.
 */
final class OvertimeLimitCheck
{
    /**
     * @param int $sameDayMinutes          already requested (pending or approved) for that date
     * @param int $sameWeekWorkdayMinutes  already requested on workdays in the same ISO week
     * @return list<array{field: string, message: string}> empty when allowed
     */
    public static function violations(
        DayType $dayType,
        int $minutes,
        int $sameDayMinutes,
        int $sameWeekWorkdayMinutes,
    ): array {
        if ($minutes <= 0 || $minutes % OvertimeRules::MINUTE_STEP !== 0) {
            return [['field' => 'hours', 'message' => 'Overtime must be a positive number of hours in steps of 0.5.']];
        }

        $errors = [];
        $dailyLimit = OvertimeRules::maxMinutesPerDay($dayType);
        if ($sameDayMinutes + $minutes > $dailyLimit) {
            $errors[] = ['field' => 'hours', 'message' => sprintf(
                'Overtime on a %s is limited to %d hours per day; %s hours already requested.',
                $dayType->isWorkday() ? 'workday' : 'rest day or public holiday',
                intdiv($dailyLimit, 60),
                self::hours($sameDayMinutes),
            )];
        }

        if ($dayType->isWorkday() && $sameWeekWorkdayMinutes + $minutes > OvertimeRules::MAX_WORKDAY_MINUTES_PER_WEEK) {
            $errors[] = ['field' => 'hours', 'message' => sprintf(
                'Workday overtime is limited to 18 hours per week; %s hours already requested this week.',
                self::hours($sameWeekWorkdayMinutes),
            )];
        }

        return $errors;
    }

    /**
     * @return array{0: string, 1: string} Monday and Sunday of the ISO week containing $date
     */
    public static function isoWeekBounds(string $date): array
    {
        $day = new DateTimeImmutable($date);
        $monday = $day->modify('-' . ((int) $day->format('N') - 1) . ' days');

        return [$monday->format('Y-m-d'), $monday->modify('+6 days')->format('Y-m-d')];
    }

    private static function hours(int $minutes): string
    {
        return rtrim(rtrim(number_format($minutes / 60, 2, '.', ''), '0'), '.');
    }
}
