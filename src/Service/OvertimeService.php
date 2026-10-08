<?php

declare(strict_types=1);

namespace HrPayroll\Service;

use HrPayroll\Auth\EmployeeScope;
use HrPayroll\Auth\Principal;
use HrPayroll\Domain\Exception\Conflict;
use HrPayroll\Domain\Exception\Forbidden;
use HrPayroll\Domain\Exception\NotFound;
use HrPayroll\Domain\Exception\ValidationFailed;
use HrPayroll\Domain\OvertimeStatus;
use HrPayroll\Domain\Payroll\OvertimeLimitCheck;
use HrPayroll\Domain\Payroll\WorkCalendar;
use HrPayroll\Domain\Role;
use HrPayroll\Query\ListQuery;
use HrPayroll\Query\Page;
use HrPayroll\Infrastructure\Clock;
use HrPayroll\Infrastructure\Database;
use HrPayroll\Repository\EmployeeRepository;
use HrPayroll\Repository\HolidayRepository;
use HrPayroll\Repository\OvertimeRepository;

final class OvertimeService
{
    public function __construct(
        private readonly Database $db,
        private readonly OvertimeRepository $overtime,
        private readonly EmployeeRepository $employees,
        private readonly HolidayRepository $holidays,
        private readonly EmployeeService $employeeService,
        private readonly PeriodLock $periodLock,
        private readonly AuditLogger $audit,
        private readonly Clock $clock,
    ) {
    }

    /**
     * @return Page<array<string, mixed>>
     */
    public function list(Principal $actor, ListQuery $query): Page
    {
        $page = $this->overtime->list($query, EmployeeScope::for($actor), PeriodRange::fromQuery($query));

        return new Page(array_map(self::present(...), $page->items), $page->total);
    }

    /**
     * @return array<string, mixed>
     */
    public function get(Principal $actor, int $id): array
    {
        $request = $this->overtime->find($id) ?? throw new NotFound('Overtime request not found.');
        $this->employeeService->assertVisible(
            $actor,
            ['id' => $request['employee_id'], 'manager_id' => $request['manager_id']],
        );

        return self::present($request);
    }

    /**
     * Employees submit for themselves. HR and admin may submit on behalf of an
     * employee (for example to record overtime agreed on paper).
     *
     * @return array<string, mixed>
     */
    public function submit(Principal $actor, ?int $employeeId, string $date, int $minutes, string $reason): array
    {
        if ($employeeId === null || $employeeId === $actor->employeeId) {
            $employeeId = $actor->requireEmployee();
        } elseif (!$actor->role->isHrOrAdmin()) {
            throw new Forbidden('You can only submit overtime for yourself.', 'out_of_scope');
        }

        return $this->db->transactional(function () use ($actor, $employeeId, $date, $minutes, $reason): array {
            $employee = $this->employees->find($employeeId, forUpdate: true);
            if ($employee === null) {
                throw ValidationFailed::single('employee_id', 'Employee does not exist.');
            }
            if ($employee['status'] !== 'active') {
                throw ValidationFailed::single('employee_id', 'Employee is not active.');
            }
            $this->periodLock->assertNotLocked($date);

            [$weekStart, $weekEnd] = OvertimeLimitCheck::isoWeekBounds($date);
            $calendar = new WorkCalendar($this->holidays->datesBetween($weekStart, $weekEnd));
            $sameDay = 0;
            $sameWeekWorkdays = 0;
            foreach ($this->overtime->activeBetween($employeeId, $weekStart, $weekEnd) as $existing) {
                if ($existing['work_date'] === $date) {
                    $sameDay += $existing['minutes'];
                }
                if ($calendar->dayType($existing['work_date'])->isWorkday()) {
                    $sameWeekWorkdays += $existing['minutes'];
                }
            }

            $dayType = $calendar->dayType($date);
            $violations = OvertimeLimitCheck::violations($dayType, $minutes, $sameDay, $sameWeekWorkdays);
            if ($violations !== []) {
                throw new ValidationFailed($violations, 'Overtime exceeds the limits of PP 35/2021.');
            }

            $id = $this->overtime->create($employeeId, $date, $minutes, $reason);
            $this->audit->record($actor, 'overtime.submitted', 'overtime_request', $id, null, [
                'employee_id' => $employeeId,
                'work_date' => $date,
                'minutes' => $minutes,
            ]);

            return $this->get($actor, $id);
        });
    }

    /**
     * Only the employee's direct manager may decide, and never on their own
     * request. Admins may decide for anyone except themselves (for example when
     * the manager is unavailable). HR cannot approve.
     *
     * @return array<string, mixed>
     */
    public function decide(Principal $actor, int $id, OvertimeStatus $decision, ?string $note): array
    {
        $actor->requireRole(Role::Manager, Role::Admin);

        return $this->db->transactional(function () use ($actor, $id, $decision, $note): array {
            $request = $this->overtime->find($id, forUpdate: true) ?? throw new NotFound('Overtime request not found.');
            $employeeId = (int) $request['employee_id'];

            if ($actor->employeeId !== null && $employeeId === $actor->employeeId) {
                throw new Forbidden('You cannot decide on your own overtime request.', 'self_approval');
            }
            $managerId = $request['manager_id'] === null ? null : (int) $request['manager_id'];
            if ($actor->role === Role::Manager && $managerId !== $actor->employeeId) {
                throw new Forbidden('You can only decide on requests from your direct reports.', 'out_of_scope');
            }
            if ($request['status'] !== OvertimeStatus::Pending->value) {
                throw new Conflict("This request was already {$request['status']}.", 'already_decided');
            }
            if ($decision === OvertimeStatus::Approved) {
                $this->periodLock->assertWritable($actor, (string) $request['work_date']);
            } else {
                $this->periodLock->assertNotLocked((string) $request['work_date']);
            }

            $now = $this->clock->now()->format(Clock::DB_FORMAT);
            $this->overtime->decide($id, $decision->value, $actor->userId, $now, $note);
            $this->audit->record(
                $actor,
                'overtime.' . $decision->value,
                'overtime_request',
                $id,
                ['status' => $request['status']],
                ['status' => $decision->value, 'note' => $note],
            );

            return $this->get($actor, $id);
        });
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function present(array $row): array
    {
        $row['hours'] = (int) $row['minutes'] / 60;
        unset($row['manager_id']);

        return $row;
    }
}
