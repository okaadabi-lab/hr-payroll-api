<?php

declare(strict_types=1);

namespace HrPayroll\Tests\Unit\Payroll;

use HrPayroll\Domain\Payroll\BpjsProgram;
use HrPayroll\Domain\Payroll\BpjsRate;
use HrPayroll\Domain\Payroll\EmployeePayInput;
use HrPayroll\Domain\Payroll\LineType;
use HrPayroll\Domain\Payroll\OvertimeEntry;
use HrPayroll\Domain\Payroll\PayrollCalculator;
use HrPayroll\Domain\Payroll\PayrollMonth;
use HrPayroll\Domain\Payroll\Payslip;
use HrPayroll\Domain\Payroll\WorkCalendar;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class PayrollCalculatorTest extends TestCase
{
    private const INDEPENDENCE_DAY = '2026-08-17';

    /**
     * @return array<string, BpjsRate>
     */
    public static function bpjsRates(int $jpCap = 10_042_300): array
    {
        return [
            'kesehatan' => new BpjsRate(BpjsProgram::Kesehatan, 100, 400, 12_000_000),
            'jht' => new BpjsRate(BpjsProgram::Jht, 200, 370, null),
            'jp' => new BpjsRate(BpjsProgram::Jp, 100, 200, $jpCap),
            'jkk' => new BpjsRate(BpjsProgram::Jkk, 0, 24, null),
            'jkm' => new BpjsRate(BpjsProgram::Jkm, 0, 30, null),
        ];
    }

    private function calculate(EmployeePayInput $input, string $month = '2026-08'): Payslip
    {
        return (new PayrollCalculator())->calculate(
            $input,
            PayrollMonth::fromString($month),
            new WorkCalendar([self::INDEPENDENCE_DAY]),
            self::bpjsRates(),
        );
    }

    private static function amount(Payslip $payslip, string $code): ?int
    {
        return $payslip->line($code)?->amount;
    }

    /**
     * The worked example in docs/payroll-rules.md is taken from this test.
     */
    public function testWorkedExampleAugust2026(): void
    {
        $input = new EmployeePayInput(
            employeeId: 7,
            baseSalary: 7_500_000,
            fixedAllowances: 1_000_000,
            absentDates: ['2026-08-20', '2026-08-21'],
            approvedOvertime: [
                new OvertimeEntry('2026-08-04', 180),
                new OvertimeEntry('2026-08-05', 120),
                new OvertimeEntry('2026-08-08', 600),
                new OvertimeEntry(self::INDEPENDENCE_DAY, 240),
            ],
        );

        $payslip = $this->calculate($input);

        self::assertSame(7_500_000, self::amount($payslip, 'base_salary'));
        self::assertSame(1_000_000, self::amount($payslip, 'fixed_allowances'));
        self::assertSame(442_197, self::amount($payslip, 'overtime_workday'));
        self::assertSame(1_523_121, self::amount($payslip, 'overtime_rest_day'));
        self::assertSame(850_000, self::amount($payslip, 'absence'));
        self::assertSame(85_000, self::amount($payslip, 'bpjs_kesehatan_employee'));
        self::assertSame(170_000, self::amount($payslip, 'bpjs_jht_employee'));
        self::assertSame(85_000, self::amount($payslip, 'bpjs_jp_employee'));
        self::assertSame(340_000, self::amount($payslip, 'bpjs_kesehatan_employer'));
        self::assertSame(314_500, self::amount($payslip, 'bpjs_jht_employer'));
        self::assertSame(170_000, self::amount($payslip, 'bpjs_jp_employer'));
        self::assertSame(20_400, self::amount($payslip, 'bpjs_jkk_employer'));
        self::assertSame(25_500, self::amount($payslip, 'bpjs_jkm_employer'));

        self::assertSame(10_465_318, $payslip->grossPay());
        self::assertSame(1_190_000, $payslip->totalDeductions());
        self::assertSame(9_275_318, $payslip->netPay());
        self::assertSame(870_400, $payslip->total(LineType::EmployerContribution));
        self::assertSame(11_335_718, $payslip->employerCost());
    }

    public function testNoOvertimeOrAbsenceProducesOnlyFixedLinesAndBpjs(): void
    {
        $payslip = $this->calculate(new EmployeePayInput(1, 5_000_000, 0));

        self::assertNull($payslip->line('overtime_workday'));
        self::assertNull($payslip->line('overtime_rest_day'));
        self::assertNull($payslip->line('absence'));
        self::assertNull($payslip->line('bpjs_jkk_employee'), 'JKK has no employee share');
        self::assertSame(5_000_000, $payslip->grossPay());
        self::assertSame(50_000 + 100_000 + 50_000, $payslip->totalDeductions());
    }

    public function testKesehatanAndJpAreCappedForHighEarners(): void
    {
        $payslip = $this->calculate(new EmployeePayInput(1, 14_000_000, 1_000_000));

        self::assertSame(120_000, self::amount($payslip, 'bpjs_kesehatan_employee'));
        self::assertSame(480_000, self::amount($payslip, 'bpjs_kesehatan_employer'));
        self::assertSame(100_423, self::amount($payslip, 'bpjs_jp_employee'));
        self::assertSame(200_846, self::amount($payslip, 'bpjs_jp_employer'));
        self::assertSame(300_000, self::amount($payslip, 'bpjs_jht_employee'), 'JHT has no cap');
        self::assertSame(555_000, self::amount($payslip, 'bpjs_jht_employer'));
        self::assertSame(36_000, self::amount($payslip, 'bpjs_jkk_employer'));
        self::assertSame(45_000, self::amount($payslip, 'bpjs_jkm_employer'));
    }

    public function testWageExactlyAtTheCapIsNotReduced(): void
    {
        $payslip = $this->calculate(new EmployeePayInput(1, 12_000_000, 0));

        self::assertSame(120_000, self::amount($payslip, 'bpjs_kesehatan_employee'));
    }

    public function testJpCapComesFromSettings(): void
    {
        $payslip = (new PayrollCalculator())->calculate(
            new EmployeePayInput(1, 11_000_000, 0),
            PayrollMonth::fromString('2026-08'),
            new WorkCalendar([]),
            self::bpjsRates(jpCap: 11_000_000),
        );

        self::assertSame(110_000, self::amount($payslip, 'bpjs_jp_employee'));
    }

    public function testBpjsSharesRoundHalfUp(): void
    {
        // JKK 0.24% of 5,312,500 = 12,750.0; JKM 0.3% of 5,312,550 = 15,937.65 -> 15,938
        $payslip = $this->calculate(new EmployeePayInput(1, 5_312_550, 0));

        self::assertSame(15_938, self::amount($payslip, 'bpjs_jkm_employer'));
        self::assertSame(12_750, self::amount($payslip, 'bpjs_jkk_employer'));
    }

    public function testAbsenceUsesWorkingDaysOfTheMonth(): void
    {
        // September 2026 has 22 working days and no holiday in this calendar.
        $payslip = $this->calculate(new EmployeePayInput(1, 8_500_000, 0, ['2026-09-10']), '2026-09');

        self::assertSame(386_364, self::amount($payslip, 'absence'));
    }

    public function testAbsenceHolidayReducesWorkingDays(): void
    {
        // August 2026: 21 weekdays minus Independence Day = 20 working days.
        $payslip = $this->calculate(new EmployeePayInput(1, 10_000_000, 0, ['2026-08-03']));

        self::assertSame(500_000, self::amount($payslip, 'absence'));
    }

    public function testAbsenceOnWeekendHolidayOrOtherMonthIsIgnored(): void
    {
        $payslip = $this->calculate(new EmployeePayInput(
            1,
            10_000_000,
            0,
            ['2026-08-08', self::INDEPENDENCE_DAY, '2026-09-01'],
        ));

        self::assertNull($payslip->line('absence'));
    }

    public function testDuplicateAbsentDatesAreCountedOnce(): void
    {
        $payslip = $this->calculate(new EmployeePayInput(1, 10_000_000, 0, ['2026-08-03', '2026-08-03']));

        self::assertSame(500_000, self::amount($payslip, 'absence'));
    }

    public function testHolidayOvertimeUsesRestDayMultipliers(): void
    {
        $onHoliday = [new OvertimeEntry(self::INDEPENDENCE_DAY, 60)];
        $onWorkday = [new OvertimeEntry('2026-08-18', 60)];
        $holiday = $this->calculate(new EmployeePayInput(1, 17_300_000, 0, [], $onHoliday));
        $workday = $this->calculate(new EmployeePayInput(1, 17_300_000, 0, [], $onWorkday));

        self::assertSame(200_000, self::amount($holiday, 'overtime_rest_day'));
        self::assertSame(150_000, self::amount($workday, 'overtime_workday'));
    }

    public function testTiersRestartEachDay(): void
    {
        // Two workdays of 1 hour each are both "first hour" at 1.5x, not 1.5x + 2x.
        $payslip = $this->calculate(new EmployeePayInput(1, 17_300_000, 0, [], [
            new OvertimeEntry('2026-08-18', 60),
            new OvertimeEntry('2026-08-19', 60),
        ]));

        self::assertSame(300_000, self::amount($payslip, 'overtime_workday'));
    }

    public function testRejectsOvertimeOutsideThePeriod(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->calculate(new EmployeePayInput(1, 1_000_000, 0, [], [new OvertimeEntry('2026-09-01', 60)]));
    }

    public function testRejectsMissingBpjsRate(): void
    {
        $rates = self::bpjsRates();
        unset($rates['jkm']);

        $this->expectException(InvalidArgumentException::class);
        (new PayrollCalculator())->calculate(
            new EmployeePayInput(1, 1_000_000, 0),
            PayrollMonth::fromString('2026-08'),
            new WorkCalendar([]),
            $rates,
        );
    }

    public function testRejectsNegativeSalary(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new EmployeePayInput(1, -1, 0);
    }
}
