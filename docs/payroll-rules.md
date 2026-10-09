# Payroll rules

This document describes every formula in `src/Domain/Payroll`. The calculator is pure
(no database, clock or globals), so each rule below is covered by unit tests in
`tests/Unit/Payroll`.

> These rules model a simplified Indonesian payroll for a portfolio project. They follow
> PP 35/2021 for overtime and the usual BPJS contribution scheme, but they are not legal
> or tax advice. PPh 21 (income tax) is out of scope.

## Money and rounding

- Every amount is an **integer number of rupiah** (`BIGINT` in MySQL, `int` in PHP).
  Floats are never used for money.
- Rates are stored as **basis points** (1 bp = 0.01 %, so 1 % = 100 bp).
- There is exactly one rounding rule, `Rounding::divideHalfUp()`: divide and round
  **half up to the nearest rupiah**. It is applied **once per payslip line**, at the end
  of that line's formula. Intermediate values (such as the hourly rate) are never rounded.

| Line | Formula | Rounded |
| --- | --- | --- |
| Base salary, fixed allowances | copied from the employee record | no (already integers) |
| Overtime on workdays | `wage x weighted_hours / 173` over all workday entries of the month | once, on the line total |
| Overtime on rest days and public holidays | same, over all rest-day and holiday entries | once, on the line total |
| Unexcused absence | `wage x absent_days / working_days` | once |
| Each BPJS share | `min(wage, cap) x rate_bp / 10000` | once per program and side |

Because the hourly rate is not rounded first, splitting the same hours across days
never changes the result by a rupiah, and the payslip can be reproduced with a pocket
calculator.

## Monthly wage

```
monthly_wage = base_salary + fixed_allowances
```

PP 35/2021 art. 32(4) says that when base plus fixed allowances is below 75 % of the total
wage (which would include non-fixed allowances), overtime uses 75 % of the total wage. This
API has no non-fixed allowances, so base plus fixed allowances is always the total wage and
the rule never applies.

## Calendar

- Five-day work week: Monday to Friday are workdays, Saturday and Sunday are weekly rest days.
- Dates in the `holidays` table are public holidays. A public holiday on a weekday is not a
  working day.
- `working_days(month)` = weekdays in the month minus weekday public holidays.

## Overtime

### Hourly rate

```
hourly_rate = monthly_wage / 173
```

### Multipliers (PP 35/2021 art. 31, five-day week)

Tiers restart every day. Hours can be requested in steps of 0.5.

| Day type | Hours | Multiplier |
| --- | --- | --- |
| Workday | 1st hour | 1.5 |
| Workday | 2nd hour onwards | 2 |
| Rest day or public holiday | 1st to 8th hour | 2 |
| Rest day or public holiday | 9th hour | 3 |
| Rest day or public holiday | 10th to 12th hour | 4 |

Internally multipliers are stored doubled (1.5 becomes 3) so the arithmetic stays in integers.

### Limits (validated when an employee submits a request)

- Workday overtime: at most **4 hours per day** and **18 hours per week** (PP 35/2021 art. 26).
  The week is the ISO week, Monday to Sunday.
- Rest days and public holidays: at most **12 hours per day**, and these hours do not count
  towards the 4 h / 18 h caps. The regulation sets pay tiers up to the 12th hour on such days,
  which cannot coexist with a 4-hour daily cap; this reading is a design decision, stated
  here so it can be changed in one place (`OvertimeLimitCheck`).
- Pending and approved requests count towards the limits; rejected requests do not.
- Only **approved** overtime is paid.

## Unexcused absence

Attendance records with status `absent` on a working day are unexcused absences.
`leave` and `sick` are paid. Absences on weekends, public holidays, or outside the month
are ignored, and each date counts once.

```
absence_deduction = monthly_wage x absent_days / working_days(month)
```

## BPJS

Rates and caps come from the `bpjs_settings` table. Each row has an `effective_from` date
(the first day of a month); a period uses, per program, the newest row that is effective on
the first day of the period. Settings are append-only, so recalculating an old period always
uses the rates that applied to it.

| Program | Employee | Employer | Wage cap (seed) |
| --- | --- | --- | --- |
| Kesehatan (health) | 1 % | 4 % | 12,000,000 |
| JHT (old-age savings) | 2 % | 3.7 % | none |
| JP (pension) | 1 % | 2 % | 10,042,300 |
| JKK (work accident) | none | 0.24 % | none |
| JKM (death) | none | 0.3 % | none |

```
contribution_base = min(monthly_wage, wage_cap)      (or monthly_wage when there is no cap)
share             = contribution_base x rate_bp / 10000
```

Employee shares are payslip **deductions**. Employer shares are **employer_contribution**
lines: they appear on the payslip for transparency but do not change net pay.

**Caps change every year** (the JP cap in particular is adjusted annually). Add a new
`bpjs_settings` row from the official BPJS announcement before running the first payroll
that it applies to. The JKK rate of 0.24 % is the lowest risk group; companies in higher
risk groups pay up to 1.74 % and must configure their own rate.

## Totals

```
gross_pay        = sum(earning lines)
total_deductions = sum(deduction lines)
net_pay          = gross_pay - total_deductions
employer_cost    = gross_pay + sum(employer_contribution lines)
```

Net pay can only go below zero when deductions exceed earnings, for example an employee
who was absent on every working day still owes their BPJS shares. The API does not carry a
negative balance into the next month; calculation stops with 409 `negative_net_pay` and
names the employees, so HR can review their attendance before anything is approved.

## Worked example

These numbers are asserted by `PayrollCalculatorTest::testWorkedExampleAugust2026` (pure
calculator) and again end to end through the API by
`PayrollLifecycleTest::testFullLifecycleDraftCalculatedApprovedPaid`.

**Employee:** Budi Santoso, base salary 7,500,000, fixed allowances 1,000,000.
**Period:** August 2026, with 17 August (Independence Day, a Monday) as a public holiday.

**Inputs**

- Approved overtime: Tue 4 Aug 3 h, Wed 5 Aug 2 h, Sat 8 Aug 10 h, Mon 17 Aug (holiday) 4 h.
- Unexcused absence: Thu 20 Aug and Fri 21 Aug.

**1. Monthly wage**

```
7,500,000 + 1,000,000 = 8,500,000
hourly rate = 8,500,000 / 173 = 49,132.947...   (not rounded)
```

**2. Workday overtime**

```
4 Aug, 3 h:  1 x 1.5 + 2 x 2 = 5.5 weighted hours
5 Aug, 2 h:  1 x 1.5 + 1 x 2 = 3.5 weighted hours
total 9 weighted hours

8,500,000 x 9 / 173 = 442,196.53...  -> 442,197
```

**3. Rest-day and holiday overtime**

```
8 Aug (Saturday), 10 h:   8 x 2 + 1 x 3 + 1 x 4 = 23 weighted hours
17 Aug (holiday),  4 h:   4 x 2                 =  8 weighted hours
total 31 weighted hours

8,500,000 x 31 / 173 = 1,523,121.39...  -> 1,523,121
```

**4. Absence**

```
working days in August 2026 = 21 weekdays - 1 weekday holiday = 20
8,500,000 x 2 / 20 = 850,000
```

**5. BPJS** (wage 8,500,000 is below both caps)

| Line | Calculation | Amount |
| --- | --- | --- |
| Kesehatan employee | 8,500,000 x 1 % | 85,000 |
| JHT employee | 8,500,000 x 2 % | 170,000 |
| JP employee | 8,500,000 x 1 % | 85,000 |
| Kesehatan employer | 8,500,000 x 4 % | 340,000 |
| JHT employer | 8,500,000 x 3.7 % | 314,500 |
| JP employer | 8,500,000 x 2 % | 170,000 |
| JKK employer | 8,500,000 x 0.24 % | 20,400 |
| JKM employer | 8,500,000 x 0.3 % | 25,500 |

**6. Payslip**

| Type | Line | Amount |
| --- | --- | --- |
| earning | Base salary | 7,500,000 |
| earning | Fixed allowances | 1,000,000 |
| earning | Overtime on workdays | 442,197 |
| earning | Overtime on rest days and public holidays | 1,523,121 |
| deduction | Unexcused absence | 850,000 |
| deduction | BPJS Kesehatan (employee) | 85,000 |
| deduction | BPJS Ketenagakerjaan JHT (employee) | 170,000 |
| deduction | BPJS Ketenagakerjaan JP (employee) | 85,000 |
| employer_contribution | Kesehatan, JHT, JP, JKK, JKM (employer) | 870,400 |

```
gross pay        = 10,465,318
total deductions =  1,190,000
net pay          =  9,275,318
employer cost    = 10,465,318 + 870,400 = 11,335,718
```

Note that the absence deduction is based on the full monthly wage, so BPJS is also
calculated on the full monthly wage, not on the wage after deductions.

## Caps example

`PayrollCalculatorTest::testKesehatanAndJpAreCappedForHighEarners`: wage 15,000,000.

```
Kesehatan employee: min(15,000,000, 12,000,000) x 1 % = 120,000
JP employee:        min(15,000,000, 10,042,300) x 1 % = 100,423
JHT employee:       15,000,000 x 2 %                  = 300,000   (no cap)
```

## Out of scope

- **PPh 21 income tax** is not calculated. A real payroll must add it (TER rates since 2024,
  annual reconciliation in December, PTKP status); producing a plausible-looking but wrong
  tax figure would be worse than leaving it out.
- Pro-rating for employees who join or leave mid-month (they are paid the full month).
- Non-fixed allowances, THR (religious holiday allowance), bonuses, loans, and BPJS
  Kesehatan for additional family members.
- The BPJS minimum wage base (contributions below the regional minimum wage).
- Six-day work weeks, which use different overtime tiers.
