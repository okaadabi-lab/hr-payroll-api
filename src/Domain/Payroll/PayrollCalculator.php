<?php

declare(strict_types=1);

namespace HrPayroll\Domain\Payroll;

use InvalidArgumentException;

/**
 * Pure monthly payroll calculation: no I/O, no clock, no globals.
 * See docs/payroll-rules.md for every formula and its rounding rule.
 */
final class PayrollCalculator
{
    /**
     * @param array<string, BpjsRate> $bpjsRates keyed by BpjsProgram value
     */
    public function calculate(
        EmployeePayInput $input,
        PayrollMonth $month,
        WorkCalendar $calendar,
        array $bpjsRates,
    ): Payslip {
        $wage = $input->monthlyWage();

        $lines = [
            new PayslipLine(LineType::Earning, 'base_salary', 'Base salary', $input->baseSalary),
            new PayslipLine(LineType::Earning, 'fixed_allowances', 'Fixed allowances', $input->fixedAllowances),
        ];
        array_push($lines, ...$this->overtimeLines($input, $month, $calendar, $wage));

        $absence = $this->absenceLine($input, $month, $calendar, $wage);
        if ($absence !== null) {
            $lines[] = $absence;
        }

        array_push($lines, ...$this->bpjsLines($bpjsRates, $wage));

        return new Payslip($input->employeeId, $month->toString(), $lines);
    }

    /**
     * @return list<PayslipLine>
     */
    private function overtimeLines(
        EmployeePayInput $input,
        PayrollMonth $month,
        WorkCalendar $calendar,
        int $wage,
    ): array {
        $buckets = [
            'workday' => ['minutes' => 0, 'weighted' => 0],
            'rest_day' => ['minutes' => 0, 'weighted' => 0],
        ];
        foreach ($input->approvedOvertime as $entry) {
            if (!$month->contains($entry->date)) {
                throw new InvalidArgumentException(
                    "Overtime on {$entry->date} is outside period {$month->toString()}."
                );
            }
            $dayType = $calendar->dayType($entry->date);
            $key = $dayType->isWorkday() ? 'workday' : 'rest_day';
            $buckets[$key]['minutes'] += $entry->minutes;
            $buckets[$key]['weighted'] += OvertimeRules::weightedMinutes($dayType, $entry->minutes);
        }

        $labels = [
            'workday' => 'Overtime on workdays',
            'rest_day' => 'Overtime on rest days and public holidays',
        ];
        $lines = [];
        foreach ($buckets as $key => $bucket) {
            if ($bucket['minutes'] === 0) {
                continue;
            }
            $lines[] = new PayslipLine(
                LineType::Earning,
                'overtime_' . $key,
                $labels[$key],
                OvertimeRules::payForWeightedMinutes($wage, $bucket['weighted']),
                sprintf(
                    '%s h, weighted %s h x (%d / 173)',
                    self::hours($bucket['minutes']),
                    self::hours(intdiv($bucket['weighted'], 2)),
                    $wage,
                ),
            );
        }

        return $lines;
    }

    private function absenceLine(
        EmployeePayInput $input,
        PayrollMonth $month,
        WorkCalendar $calendar,
        int $wage,
    ): ?PayslipLine {
        $absentWorkdays = [];
        foreach ($input->absentDates as $date) {
            if ($month->contains($date) && $calendar->dayType($date)->isWorkday()) {
                $absentWorkdays[$date] = true;
            }
        }
        $absentDays = count($absentWorkdays);
        if ($absentDays === 0) {
            return null;
        }

        $workingDays = $calendar->workingDaysIn($month);
        $amount = Rounding::divideHalfUp($wage * $absentDays, $workingDays);

        return new PayslipLine(
            LineType::Deduction,
            'absence',
            'Unexcused absence',
            $amount,
            sprintf('%d / %d working days x %d', $absentDays, $workingDays, $wage),
        );
    }

    /**
     * @param array<string, BpjsRate> $rates
     * @return list<PayslipLine>
     */
    private function bpjsLines(array $rates, int $wage): array
    {
        $employee = [];
        $employer = [];
        foreach (BpjsProgram::cases() as $program) {
            $rate = $rates[$program->value] ?? null;
            if ($rate === null) {
                throw new InvalidArgumentException("Missing BPJS rate for {$program->value}.");
            }
            $base = $rate->contributionBase($wage);
            if ($rate->employeeBasisPoints > 0) {
                $employee[] = new PayslipLine(
                    LineType::Deduction,
                    "bpjs_{$program->value}_employee",
                    $program->label() . ' (employee)',
                    $rate->employeeShare($wage),
                    self::rateBasis($rate->employeeBasisPoints, $base),
                );
            }
            if ($rate->employerBasisPoints > 0) {
                $employer[] = new PayslipLine(
                    LineType::EmployerContribution,
                    "bpjs_{$program->value}_employer",
                    $program->label() . ' (employer)',
                    $rate->employerShare($wage),
                    self::rateBasis($rate->employerBasisPoints, $base),
                );
            }
        }

        return [...$employee, ...$employer];
    }

    private static function rateBasis(int $basisPoints, int $base): string
    {
        return sprintf('%s%% of %d', rtrim(rtrim(number_format($basisPoints / 100, 2, '.', ''), '0'), '.'), $base);
    }

    private static function hours(int $minutes): string
    {
        return rtrim(rtrim(number_format($minutes / 60, 2, '.', ''), '0'), '.');
    }
}
