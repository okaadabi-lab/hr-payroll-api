<?php

declare(strict_types=1);

namespace HrPayroll\Service;

use HrPayroll\Auth\EmployeeScope;
use HrPayroll\Auth\Principal;
use HrPayroll\Domain\Exception\NotFound;
use HrPayroll\Domain\PeriodStatus;
use HrPayroll\Domain\Role;
use HrPayroll\Query\ListQuery;
use HrPayroll\Query\Page;
use HrPayroll\Repository\PayrollPeriodRepository;
use HrPayroll\Repository\PayslipRepository;

/**
 * HR and admin see every payslip. Everyone else, managers included, sees only
 * their own, and only once the period is approved.
 */
final class PayslipService
{
    private const PUBLISHED = [PeriodStatus::Approved->value, PeriodStatus::Paid->value];

    public function __construct(
        private readonly PayslipRepository $payslips,
        private readonly PayrollPeriodRepository $periods,
    ) {
    }

    /**
     * @return Page<array<string, mixed>>
     */
    public function list(Principal $actor, ListQuery $query): Page
    {
        if ($actor->role->isHrOrAdmin()) {
            return $this->payslips->list($query, EmployeeScope::for($actor), null, null);
        }

        return $this->payslips->list($query, EmployeeScope::self($actor->requireEmployee()), null, self::PUBLISHED);
    }

    /**
     * @return Page<array<string, mixed>>
     */
    public function listForPeriod(Principal $actor, int $periodId, ListQuery $query): Page
    {
        $actor->requireRole(Role::Admin, Role::Hr);
        if ($this->periods->find($periodId) === null) {
            throw new NotFound('Payroll period not found.');
        }

        return $this->payslips->list($query, EmployeeScope::for($actor), $periodId, null);
    }

    /**
     * Returns 404 rather than 403 for other people's payslips so their
     * existence is not revealed.
     *
     * @return array<string, mixed>
     */
    public function get(Principal $actor, int $id): array
    {
        $payslip = $this->payslips->find($id) ?? throw new NotFound('Payslip not found.');
        if (!$actor->role->isHrOrAdmin()) {
            $own = (int) $payslip['employee_id'] === $actor->employeeId;
            if (!$own || !in_array($payslip['period_status'], self::PUBLISHED, true)) {
                throw new NotFound('Payslip not found.');
            }
        }

        return $payslip;
    }
}
