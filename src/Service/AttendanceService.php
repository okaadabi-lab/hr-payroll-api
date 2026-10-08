<?php

declare(strict_types=1);

namespace HrPayroll\Service;

use DateTimeZone;
use HrPayroll\Auth\EmployeeScope;
use HrPayroll\Auth\Principal;
use HrPayroll\Domain\AttendanceStatus;
use HrPayroll\Domain\Exception\Conflict;
use HrPayroll\Domain\Exception\NotFound;
use HrPayroll\Domain\Exception\ValidationFailed;
use HrPayroll\Domain\Role;
use HrPayroll\Http\ListQuery;
use HrPayroll\Http\Page;
use HrPayroll\Infrastructure\Clock;
use HrPayroll\Infrastructure\Database;
use HrPayroll\Repository\AttendanceRepository;
use HrPayroll\Repository\EmployeeRepository;

final class AttendanceService
{
    public function __construct(
        private readonly Database $db,
        private readonly AttendanceRepository $attendance,
        private readonly EmployeeRepository $employees,
        private readonly EmployeeService $employeeService,
        private readonly PeriodLock $periodLock,
        private readonly AuditLogger $audit,
        private readonly Clock $clock,
        private readonly DateTimeZone $workTimezone,
    ) {
    }

    /**
     * @return Page<array<string, mixed>>
     */
    public function list(Principal $actor, ListQuery $query): Page
    {
        return $this->attendance->list($query, EmployeeScope::for($actor), PeriodRange::fromQuery($query));
    }

    /**
     * @return array<string, mixed>
     */
    public function get(Principal $actor, int $id): array
    {
        $record = $this->attendance->find($id) ?? throw new NotFound('Attendance record not found.');
        $this->employeeService->assertVisible(
            $actor,
            ['id' => $record['employee_id'], 'manager_id' => $record['manager_id']],
        );
        unset($record['manager_id']);

        return $record;
    }

    /**
     * @param array{
     *     employee_id: int,
     *     work_date: string,
     *     status: string,
     *     clock_in: ?string,
     *     clock_out: ?string,
     *     note: ?string
     * } $values
     * @return array<string, mixed>
     */
    public function create(Principal $actor, array $values): array
    {
        $actor->requireRole(Role::Admin, Role::Hr);
        self::assertConsistent($values);

        return $this->db->transactional(function () use ($actor, $values): array {
            if ($this->employees->find($values['employee_id']) === null) {
                throw ValidationFailed::single('employee_id', 'Employee does not exist.');
            }
            $this->periodLock->assertWritable($actor, $values['work_date']);
            if ($this->attendance->existsFor($values['employee_id'], $values['work_date'])) {
                throw new Conflict('Attendance for this employee and date already exists.', 'duplicate_attendance');
            }
            $id = $this->attendance->create($values);
            $this->audit->record($actor, 'attendance.created', 'attendance', $id, null, $values);

            return $this->get($actor, $id);
        });
    }

    /**
     * @param array{status: string, clock_in: ?string, clock_out: ?string, note: ?string} $values
     * @return array<string, mixed>
     */
    public function update(Principal $actor, int $id, array $values): array
    {
        $actor->requireRole(Role::Admin, Role::Hr);
        self::assertConsistent($values);

        return $this->db->transactional(function () use ($actor, $id, $values): array {
            $current = $this->attendance->find($id) ?? throw new NotFound('Attendance record not found.');
            $this->periodLock->assertWritable($actor, (string) $current['work_date']);
            $this->attendance->update($id, $values);
            $this->audit->record(
                $actor,
                'attendance.updated',
                'attendance',
                $id,
                array_intersect_key($current, $values),
                $values,
            );

            return $this->get($actor, $id);
        });
    }

    public function delete(Principal $actor, int $id): void
    {
        $actor->requireRole(Role::Admin, Role::Hr);

        $this->db->transactional(function () use ($actor, $id): void {
            $current = $this->attendance->find($id) ?? throw new NotFound('Attendance record not found.');
            $this->periodLock->assertWritable($actor, (string) $current['work_date']);
            $this->attendance->delete($id);
            $this->audit->record($actor, 'attendance.deleted', 'attendance', $id, $current, null);
        });
    }

    /**
     * Self-service clock-in for today in the company's time zone.
     *
     * @return array<string, mixed>
     */
    public function clockIn(Principal $actor): array
    {
        $employeeId = $actor->requireEmployee();
        $now = $this->clock->now()->setTimezone($this->workTimezone);
        $date = $now->format('Y-m-d');

        return $this->db->transactional(function () use ($actor, $employeeId, $date, $now): array {
            $this->periodLock->assertWritable($actor, $date);
            if ($this->attendance->existsFor($employeeId, $date)) {
                throw new Conflict('You have already clocked in today.', 'already_clocked_in');
            }
            $id = $this->attendance->create([
                'employee_id' => $employeeId,
                'work_date' => $date,
                'status' => AttendanceStatus::Present->value,
                'clock_in' => $now->format('H:i:s'),
                'clock_out' => null,
                'note' => null,
            ]);

            return $this->get($actor, $id);
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function clockOut(Principal $actor): array
    {
        $employeeId = $actor->requireEmployee();
        $now = $this->clock->now()->setTimezone($this->workTimezone);
        $date = $now->format('Y-m-d');

        return $this->db->transactional(function () use ($actor, $employeeId, $date, $now): array {
            $this->periodLock->assertWritable($actor, $date);
            $record = $this->db->fetchOne(
                'SELECT id, status, clock_in, clock_out, note FROM attendance_records
                 WHERE employee_id = ? AND work_date = ? FOR UPDATE',
                [$employeeId, $date],
            );
            if ($record === null || $record['clock_in'] === null) {
                throw new Conflict('You have not clocked in today.', 'not_clocked_in');
            }
            if ($record['clock_out'] !== null) {
                throw new Conflict('You have already clocked out today.', 'already_clocked_out');
            }
            $clockOut = $now->format('H:i:s');
            if ($clockOut <= (string) $record['clock_in']) {
                throw new Conflict('Clock-out must be later than clock-in.', 'invalid_clock_out');
            }
            $this->attendance->update((int) $record['id'], [
                'status' => (string) $record['status'],
                'clock_in' => (string) $record['clock_in'],
                'clock_out' => $clockOut,
                'note' => $record['note'] === null ? null : (string) $record['note'],
            ]);

            return $this->get($actor, (int) $record['id']);
        });
    }

    /**
     * @param array{status: string, clock_in: ?string, clock_out: ?string} $values
     */
    public static function assertConsistent(array $values): void
    {
        $errors = self::consistencyErrors($values);
        if ($errors !== []) {
            throw new ValidationFailed($errors);
        }
    }

    /**
     * @param array{status: string, clock_in: ?string, clock_out: ?string} $values
     * @return list<array{field: string, message: string}>
     */
    public static function consistencyErrors(array $values): array
    {
        $errors = [];
        $present = $values['status'] === AttendanceStatus::Present->value;
        if (!$present && ($values['clock_in'] !== null || $values['clock_out'] !== null)) {
            $errors[] = ['field' => 'status', 'message' => 'Only present records can have clock times.'];
        }
        if ($values['clock_out'] !== null && $values['clock_in'] === null) {
            $errors[] = ['field' => 'clock_out', 'message' => 'Clock-out requires a clock-in.'];
        }
        $bothClocks = $values['clock_in'] !== null && $values['clock_out'] !== null;
        if ($bothClocks && $values['clock_out'] <= $values['clock_in']) {
            $errors[] = ['field' => 'clock_out', 'message' => 'Clock-out must be later than clock-in.'];
        }

        return $errors;
    }
}
