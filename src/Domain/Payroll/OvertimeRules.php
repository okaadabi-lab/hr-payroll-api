<?php

declare(strict_types=1);

namespace HrPayroll\Domain\Payroll;

/**
 * Overtime limits and pay multipliers from PP 35/2021 for a five-day work week.
 *
 * Multipliers are stored doubled (3 = 1.5x) so every calculation stays in integers.
 */
final class OvertimeRules
{
    public const HOURLY_DIVISOR = 173;
    public const MAX_WORKDAY_MINUTES_PER_DAY = 4 * 60;
    public const MAX_WORKDAY_MINUTES_PER_WEEK = 18 * 60;
    public const MAX_REST_DAY_MINUTES_PER_DAY = 12 * 60;
    public const MINUTE_STEP = 30;

    private const WORKDAY_TIERS = [[60, 3], [null, 4]];
    private const REST_DAY_TIERS = [[8 * 60, 4], [60, 6], [3 * 60, 8]];

    /**
     * Splits overtime minutes into consecutive tiers.
     *
     * @return list<array{minutes: int, doubledMultiplier: int}>
     */
    public static function segments(DayType $dayType, int $minutes): array
    {
        $tiers = $dayType->isWorkday() ? self::WORKDAY_TIERS : self::REST_DAY_TIERS;
        $remaining = $minutes;
        $segments = [];
        foreach ($tiers as [$tierMinutes, $doubledMultiplier]) {
            if ($remaining <= 0) {
                break;
            }
            $take = $tierMinutes === null ? $remaining : min($remaining, $tierMinutes);
            $segments[] = ['minutes' => $take, 'doubledMultiplier' => $doubledMultiplier];
            $remaining -= $take;
        }

        return $segments;
    }

    public static function maxMinutesPerDay(DayType $dayType): int
    {
        return $dayType->isWorkday() ? self::MAX_WORKDAY_MINUTES_PER_DAY : self::MAX_REST_DAY_MINUTES_PER_DAY;
    }

    /**
     * Overtime minutes multiplied by their tier multipliers (still doubled).
     */
    public static function weightedMinutes(DayType $dayType, int $minutes): int
    {
        $weighted = 0;
        foreach (self::segments($dayType, $minutes) as $segment) {
            $weighted += $segment['minutes'] * $segment['doubledMultiplier'];
        }

        return $weighted;
    }

    /**
     * monthlyWage / 173 x weighted hours, rounded half up once. The hourly rate
     * itself is never rounded, so splitting the same hours across days does not
     * change the result.
     */
    public static function payForWeightedMinutes(int $monthlyWage, int $doubledWeightedMinutes): int
    {
        return Rounding::divideHalfUp($monthlyWage * $doubledWeightedMinutes, self::HOURLY_DIVISOR * 60 * 2);
    }
}
