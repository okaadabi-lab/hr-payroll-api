<?php

declare(strict_types=1);

namespace HrPayroll\Service;

use HrPayroll\Auth\Principal;
use HrPayroll\Domain\Exception\Conflict;
use HrPayroll\Domain\Exception\Forbidden;
use HrPayroll\Domain\Exception\NotFound;
use HrPayroll\Domain\Payroll\BpjsProgram;
use HrPayroll\Domain\Payroll\EmployeePayInput;
use HrPayroll\Domain\Payroll\OvertimeEntry;
use HrPayroll\Domain\Payroll\PayrollCalculator;
use HrPayroll\Domain\Payroll\PayrollMonth;
use HrPayroll\Domain\Payroll\WorkCalendar;
use HrPayroll\Domain\PeriodStatus;
use HrPayroll\Domain\Role;
use HrPayroll\Http\ListQuery;
use HrPayroll\Http\Page;
use HrPayroll\Infrastructure\Clock;
use HrPayroll\Infrastructure\Database;
use HrPayroll\Repository\AttendanceRepository;
use HrPayroll\Repository\BpjsSettingRepository;
use HrPayroll\Repository\EmployeeRepository;
use HrPayroll\Repository\HolidayRepository;
use HrPayroll\Repository\OutboxRepository;
use HrPayroll\Repository\OvertimeRepository;
use HrPayroll\Repository\PayrollPeriodRepository;
use HrPayroll\Repository\PayslipRepository;

/**
 * Payroll period lifecycle: draft -> calculated -> approved -> paid.
 * Each transition runs in one transaction with its audit entry and, where
 * relevant, its outbox events.
 */
final class PayrollService
{
    public const EVENT_PAYSLIP_PUBLISHED = 'payslip.published';
    public const EVENT_PAYROLL_PAID = 'payroll.paid';

    public function __construct(
        private readonly Database $db,
        private readonly PayrollPeriodRepository $periods,
        private readonly PayslipRepository $payslips,
        private readonly EmployeeRepository $employees,
        private readonly AttendanceRepository $attendance,
        private readonly OvertimeRepository $overtime,
        private readonly HolidayRepository $holidays,
        private readonly BpjsSettingRepository $bpjs,
        private readonly OutboxRepository $outbox,
        private readonly PayrollCalculator $calculator,
        private readonly AuditLogger $audit,
        private readonly Clock $clock,
        private readonly string $appUrl,
    ) {
    }

    /**
     * @return Page<array<string, mixed>>
     */
    public function list(Principal $actor, ListQuery $query): Page
    {
        $actor->requireRole(Role::Admin, Role::Hr);

        return $this->periods->list($query);
    }

    /**
     * @return array<string, mixed>
     */
    public function get(Principal $actor, int $id): array
    {
        $actor->requireRole(Role::Admin, Role::Hr);
        $period = $this->periods->find($id) ?? throw new NotFound('Payroll period not found.');
        $period['totals'] = $this->payslips->totalsForPeriod($id);

        return $period;
    }

    /**
     * @return array<string, mixed>
     */
    public function create(Principal $actor, PayrollMonth $month): array
    {
        $actor->requireRole(Role::Admin, Role::Hr);

        return $this->db->transactional(function () use ($actor, $month): array {
            if ($this->periods->existsForPeriod($month->toString())) {
                throw new Conflict("Payroll period {$month->toString()} already exists.", 'duplicate_period');
            }
            $id = $this->periods->create($month->toString(), $actor->userId);
            $after = ['period' => $month->toString()];
            $this->audit->record($actor, 'payroll.created', 'payroll_period', $id, null, $after);

            return $this->get($actor, $id);
        });
    }

    /**
     * Replaces all payslips of the period with a fresh calculation. Allowed
     * while the period is draft or calculated.
     *
     * @return array<string, mixed>
     */
    public function calculate(Principal $actor, int $id, ?string $note): array
    {
        $actor->requireRole(Role::Admin, Role::Hr);

        return $this->db->transactional(function () use ($actor, $id, $note): array {
            $period = $this->periods->find($id, forUpdate: true) ?? throw new NotFound('Payroll period not found.');
            $status = PeriodStatus::from((string) $period['status']);
            if (!$status->canCalculate()) {
                throw new Conflict("Payroll period is {$status->value} and cannot be recalculated.", 'period_locked');
            }

            $month = PayrollMonth::fromString((string) $period['period']);
            $rates = $this->bpjs->effectiveOn($month->firstDay());
            $missing = array_diff(array_column(BpjsProgram::cases(), 'value'), array_keys($rates));
            if ($missing !== []) {
                throw new Conflict(
                    'BPJS settings are missing for: ' . implode(', ', $missing) . '.',
                    'bpjs_settings_missing',
                );
            }

            $calendar = new WorkCalendar($this->holidays->datesBetween($month->firstDay(), $month->lastDay()));
            $absences = $this->attendance->absentDatesBetween($month->firstDay(), $month->lastDay());
            $overtime = $this->overtime->approvedBetween($month->firstDay(), $month->lastDay());

            $this->payslips->deleteForPeriod($id);
            foreach ($this->employees->activeForPayroll($month->lastDay()) as $employee) {
                $employeeId = (int) $employee['id'];
                $input = new EmployeePayInput(
                    $employeeId,
                    (int) $employee['base_salary'],
                    (int) $employee['fixed_allowances'],
                    $absences[$employeeId] ?? [],
                    array_map(
                        static fn (array $o): OvertimeEntry => new OvertimeEntry($o['work_date'], $o['minutes']),
                        $overtime[$employeeId] ?? [],
                    ),
                );
                $this->payslips->store($id, $this->calculator->calculate($input, $month, $calendar, $rates), [
                    'employee_number' => (string) $employee['employee_number'],
                    'employee_name' => (string) $employee['full_name'],
                    'department_name' => (string) $employee['department_name'],
                ]);
            }

            $this->periods->markCalculated($id, $actor->userId, $this->now(), $note);
            $totals = $this->payslips->totalsForPeriod($id);
            $this->audit->record(
                $actor,
                $status === PeriodStatus::Draft ? 'payroll.calculated' : 'payroll.recalculated',
                'payroll_period',
                $id,
                ['status' => $status->value],
                ['status' => PeriodStatus::Calculated->value, 'totals' => $totals],
            );

            return $this->get($actor, $id);
        });
    }

    /**
     * Segregation of duties: approval needs an admin who did not run the
     * calculation being approved.
     *
     * @return array<string, mixed>
     */
    public function approve(Principal $actor, int $id): array
    {
        $actor->requireRole(Role::Admin);

        return $this->db->transactional(function () use ($actor, $id): array {
            $period = $this->periods->find($id, forUpdate: true) ?? throw new NotFound('Payroll period not found.');
            if ($period['status'] !== PeriodStatus::Calculated->value) {
                throw new Conflict(
                    "Only a calculated period can be approved; this one is {$period['status']}.",
                    'invalid_transition',
                );
            }
            if ((int) $period['calculated_by'] === $actor->userId) {
                throw new Forbidden('The user who calculated a payroll cannot approve it.', 'segregation_of_duties');
            }

            $this->periods->markApproved($id, $actor->userId, $this->now());
            $this->audit->record(
                $actor,
                'payroll.approved',
                'payroll_period',
                $id,
                ['status' => PeriodStatus::Calculated->value],
                ['status' => PeriodStatus::Approved->value],
            );
            $this->publishPerPayslip(self::EVENT_PAYSLIP_PUBLISHED, $id, (string) $period['period']);

            return $this->get($actor, $id);
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function markPaid(Principal $actor, int $id): array
    {
        $actor->requireRole(Role::Admin);

        return $this->db->transactional(function () use ($actor, $id): array {
            $period = $this->periods->find($id, forUpdate: true) ?? throw new NotFound('Payroll period not found.');
            if ($period['status'] !== PeriodStatus::Approved->value) {
                throw new Conflict(
                    "Only an approved period can be marked paid; this one is {$period['status']}.",
                    'invalid_transition',
                );
            }

            $this->periods->markPaid($id, $actor->userId, $this->now());
            $this->audit->record(
                $actor,
                'payroll.paid',
                'payroll_period',
                $id,
                ['status' => PeriodStatus::Approved->value],
                ['status' => PeriodStatus::Paid->value],
            );
            $this->publishPerPayslip(self::EVENT_PAYROLL_PAID, $id, (string) $period['period']);

            return $this->get($actor, $id);
        });
    }

    /**
     * One event per payslip with a stable payload, so a consumer can notify
     * each employee without calling back into the API.
     */
    private function publishPerPayslip(string $type, int $periodId, string $period): void
    {
        $occurredAt = $this->now();
        foreach ($this->payslips->forOutbox($periodId) as $payslip) {
            $this->outbox->append($type, 'payslip', (int) $payslip['id'], [
                'employee_id' => (int) $payslip['employee_id'],
                'employee_name' => (string) $payslip['employee_name'],
                'phone' => $payslip['phone'] === null ? null : (string) $payslip['phone'],
                'period' => $period,
                'net_pay' => (int) $payslip['net_pay'],
                'payslip_url' => "{$this->appUrl}/api/v1/payslips/{$payslip['id']}",
            ], $occurredAt);
        }
    }

    private function now(): string
    {
        return $this->clock->now()->format(Clock::DB_FORMAT);
    }
}
