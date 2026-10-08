<?php

declare(strict_types=1);

namespace HrPayroll\Tests\Unit\Payroll;

use HrPayroll\Domain\Payroll\DayType;
use HrPayroll\Domain\Payroll\OvertimeRules;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class OvertimeRulesTest extends TestCase
{
    private const WAGE = 17_300_000; // hourly rate 100,000 keeps the expectations readable

    /**
     * @return iterable<string, array{DayType, float, int}>
     */
    public static function tiers(): iterable
    {
        yield 'workday half hour at 1.5x' => [DayType::Workday, 0.5, 75_000];
        yield 'workday first hour at 1.5x' => [DayType::Workday, 1, 150_000];
        yield 'workday second hour at 2x' => [DayType::Workday, 2, 350_000];
        yield 'workday four hours' => [DayType::Workday, 4, 750_000];
        yield 'rest day one hour at 2x' => [DayType::RestDay, 1, 200_000];
        yield 'rest day eight hours all at 2x' => [DayType::RestDay, 8, 1_600_000];
        yield 'rest day 9th hour at 3x' => [DayType::RestDay, 9, 1_900_000];
        yield 'rest day 10th hour at 4x' => [DayType::RestDay, 10, 2_300_000];
        yield 'rest day 12 hours' => [DayType::RestDay, 12, 3_100_000];
        yield 'public holiday uses rest day tiers' => [DayType::PublicHoliday, 9.5, 2_100_000];
    }

    #[DataProvider('tiers')]
    public function testMultiplierTiers(DayType $dayType, float $hours, int $expectedPay): void
    {
        $weighted = OvertimeRules::weightedMinutes($dayType, (int) round($hours * 60));

        self::assertSame($expectedPay, OvertimeRules::payForWeightedMinutes(self::WAGE, $weighted));
    }

    public function testWorkdaySegments(): void
    {
        self::assertSame(
            [['minutes' => 60, 'doubledMultiplier' => 3], ['minutes' => 150, 'doubledMultiplier' => 4]],
            OvertimeRules::segments(DayType::Workday, 210),
        );
    }

    public function testRestDaySegments(): void
    {
        self::assertSame(
            [
                ['minutes' => 480, 'doubledMultiplier' => 4],
                ['minutes' => 60, 'doubledMultiplier' => 6],
                ['minutes' => 120, 'doubledMultiplier' => 8],
            ],
            OvertimeRules::segments(DayType::RestDay, 660),
        );
    }

    public function testHourlyRateIsNotRoundedBeforeMultiplying(): void
    {
        // 8,500,000 / 173 = 49,132.95...; rounding the rate first would give 49,133 x 1.5 = 73,700.
        $weighted = OvertimeRules::weightedMinutes(DayType::Workday, 60);

        self::assertSame(73_699, OvertimeRules::payForWeightedMinutes(8_500_000, $weighted));
    }

    public function testPayRoundsHalfUpOnce(): void
    {
        // 173 x 1.5 = 259.5 rupiah -> 260
        $weighted = OvertimeRules::weightedMinutes(DayType::Workday, 60);

        self::assertSame(260, OvertimeRules::payForWeightedMinutes(173 * 173, $weighted));
    }
}
