<?php

declare(strict_types=1);

namespace HrPayroll\Tests\Unit\Payroll;

use HrPayroll\Domain\Payroll\DayType;
use HrPayroll\Domain\Payroll\PayrollMonth;
use HrPayroll\Domain\Payroll\WorkCalendar;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class WorkCalendarTest extends TestCase
{
    public function testClassifiesWeekdaysWeekendsAndHolidays(): void
    {
        $calendar = new WorkCalendar(['2026-08-17']);

        self::assertSame(DayType::Workday, $calendar->dayType('2026-08-18'));
        self::assertSame(DayType::RestDay, $calendar->dayType('2026-08-15'));
        self::assertSame(DayType::RestDay, $calendar->dayType('2026-08-16'));
        self::assertSame(DayType::PublicHoliday, $calendar->dayType('2026-08-17'));
    }

    public function testHolidayOnWeekendStaysAHoliday(): void
    {
        $calendar = new WorkCalendar(['2026-08-16']);

        self::assertSame(DayType::PublicHoliday, $calendar->dayType('2026-08-16'));
    }

    public function testWorkingDaysExcludeWeekendsAndWeekdayHolidays(): void
    {
        $month = PayrollMonth::fromString('2026-08');

        self::assertSame(21, (new WorkCalendar([]))->workingDaysIn($month));
        self::assertSame(20, (new WorkCalendar(['2026-08-17']))->workingDaysIn($month));
        $sundayHoliday = new WorkCalendar(['2026-08-16']);
        self::assertSame(21, $sundayHoliday->workingDaysIn($month), 'Sunday holiday changes nothing');
    }

    public function testRejectsInvalidDate(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new WorkCalendar([]))->dayType('2026-02-30');
    }

    public function testPayrollMonthBounds(): void
    {
        $month = PayrollMonth::fromString('2028-02');

        self::assertSame('2028-02-01', $month->firstDay());
        self::assertSame('2028-02-29', $month->lastDay());
        self::assertCount(29, $month->dates());
        self::assertTrue($month->contains('2028-02-29'));
        self::assertFalse($month->contains('2028-03-01'));
    }

    public function testPayrollMonthRejectsBadFormat(): void
    {
        $this->expectException(InvalidArgumentException::class);
        PayrollMonth::fromString('2026-13');
    }
}
