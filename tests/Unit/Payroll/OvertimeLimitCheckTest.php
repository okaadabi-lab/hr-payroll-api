<?php

declare(strict_types=1);

namespace HrPayroll\Tests\Unit\Payroll;

use HrPayroll\Domain\Payroll\DayType;
use HrPayroll\Domain\Payroll\OvertimeLimitCheck;
use PHPUnit\Framework\TestCase;

final class OvertimeLimitCheckTest extends TestCase
{
    public function testAllowsFourHoursOnAWorkday(): void
    {
        self::assertSame([], OvertimeLimitCheck::violations(DayType::Workday, 240, 0, 0));
    }

    public function testRejectsMoreThanFourHoursOnAWorkday(): void
    {
        self::assertCount(1, OvertimeLimitCheck::violations(DayType::Workday, 270, 0, 0));
    }

    public function testCountsExistingRequestsOnTheSameDay(): void
    {
        self::assertCount(1, OvertimeLimitCheck::violations(DayType::Workday, 120, 180, 180));
        self::assertSame([], OvertimeLimitCheck::violations(DayType::Workday, 60, 180, 180));
    }

    public function testWeeklyLimitIsEighteenHours(): void
    {
        self::assertSame([], OvertimeLimitCheck::violations(DayType::Workday, 120, 0, 16 * 60));
        $errors = OvertimeLimitCheck::violations(DayType::Workday, 150, 0, 16 * 60);

        self::assertCount(1, $errors);
        self::assertStringContainsString('18 hours per week', $errors[0]['message']);
    }

    public function testRestDayOvertimeIsNotCountedTowardsTheWorkdayCaps(): void
    {
        self::assertSame([], OvertimeLimitCheck::violations(DayType::RestDay, 12 * 60, 0, 18 * 60));
        self::assertSame([], OvertimeLimitCheck::violations(DayType::PublicHoliday, 10 * 60, 0, 18 * 60));
    }

    public function testRestDayIsCappedAtTwelveHours(): void
    {
        self::assertCount(1, OvertimeLimitCheck::violations(DayType::RestDay, 12 * 60 + 30, 0, 0));
    }

    public function testRejectsZeroAndNonHalfHourSteps(): void
    {
        self::assertCount(1, OvertimeLimitCheck::violations(DayType::Workday, 0, 0, 0));
        self::assertCount(1, OvertimeLimitCheck::violations(DayType::Workday, 45, 0, 0));
    }

    public function testIsoWeekBoundsRunMondayToSunday(): void
    {
        self::assertSame(['2026-08-31', '2026-09-06'], OvertimeLimitCheck::isoWeekBounds('2026-09-03'));
        self::assertSame(['2026-08-31', '2026-09-06'], OvertimeLimitCheck::isoWeekBounds('2026-09-06'));
        self::assertSame(['2026-09-07', '2026-09-13'], OvertimeLimitCheck::isoWeekBounds('2026-09-07'));
    }
}
