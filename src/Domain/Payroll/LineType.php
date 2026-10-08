<?php

declare(strict_types=1);

namespace HrPayroll\Domain\Payroll;

enum LineType: string
{
    case Earning = 'earning';
    case Deduction = 'deduction';
    case EmployerContribution = 'employer_contribution';
}
