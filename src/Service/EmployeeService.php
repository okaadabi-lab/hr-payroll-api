<?php

declare(strict_types=1);

namespace HrPayroll\Service;

use HrPayroll\Auth\EmployeeScope;
use HrPayroll\Auth\Principal;
use HrPayroll\Domain\Exception\Conflict;
use HrPayroll\Domain\Exception\Forbidden;
use HrPayroll\Domain\Exception\NotFound;
use HrPayroll\Domain\Exception\ValidationFailed;
use HrPayroll\Domain\Role;
use HrPayroll\Query\ListQuery;
use HrPayroll\Query\Page;
use HrPayroll\Infrastructure\Database;
use HrPayroll\Repository\DepartmentRepository;
use HrPayroll\Repository\EmployeeRepository;

final class EmployeeService
{
    private const SALARY_FIELDS = ['base_salary', 'fixed_allowances'];

    public function __construct(
        private readonly Database $db,
        private readonly EmployeeRepository $employees,
        private readonly DepartmentRepository $departments,
        private readonly PeriodLock $periodLock,
        private readonly AuditLogger $audit,
    ) {
    }

    /**
     * @return Page<array<string, mixed>>
     */
    public function list(Principal $actor, ListQuery $query): Page
    {
        $page = $this->employees->list($query, EmployeeScope::for($actor));

        return new Page(array_map(fn (array $row): array => $this->present($actor, $row), $page->items), $page->total);
    }

    /**
     * @return array<string, mixed>
     */
    public function get(Principal $actor, int $id): array
    {
        $employee = $this->employees->find($id) ?? throw new NotFound('Employee not found.');
        $this->assertVisible($actor, $employee);

        return $this->present($actor, $employee);
    }

    /**
     * @param array<string, scalar|null> $values validated input
     * @return array<string, mixed>
     */
    public function create(Principal $actor, array $values): array
    {
        $actor->requireRole(Role::Admin, Role::Hr);

        return $this->db->transactional(function () use ($actor, $values): array {
            $this->assertReferences($values, null);
            if ($this->employees->numberOrEmailTaken((string) $values['employee_number'], (string) $values['email'])) {
                throw new Conflict('Employee number or email is already in use.', 'duplicate_employee');
            }
            $id = $this->employees->create($values);
            $this->audit->record($actor, 'employee.created', 'employee', $id, null, $values);
            $hiredMonth = substr((string) $values['hired_at'], 0, 7);
            $this->periodLock->invalidateCalculatedFrom($actor, $hiredMonth, 'employee added');

            return $this->get($actor, $id);
        });
    }

    /**
     * Salary changes are written to the audit log with before and after values
     * in the same transaction as the update.
     *
     * @param array<string, scalar|null> $changes validated input, only the fields being changed
     * @return array<string, mixed>
     */
    public function update(Principal $actor, int $id, array $changes): array
    {
        $actor->requireRole(Role::Admin, Role::Hr);

        return $this->db->transactional(function () use ($actor, $id, $changes): array {
            $current = $this->employees->find($id, forUpdate: true) ?? throw new NotFound('Employee not found.');
            $this->assertReferences($changes, $id);

            $number = (string) ($changes['employee_number'] ?? $current['employee_number']);
            $email = (string) ($changes['email'] ?? $current['email']);
            if (
                (isset($changes['employee_number']) || isset($changes['email']))
                && $this->employees->numberOrEmailTaken($number, $email, $id)
            ) {
                throw new Conflict('Employee number or email is already in use.', 'duplicate_employee');
            }

            $changed = [];
            foreach ($changes as $field => $value) {
                if ($current[$field] !== $value) {
                    $changed[$field] = $value;
                }
            }
            $this->employees->update($id, $changed);
            if ($changed !== []) {
                $this->periodLock->invalidateAllCalculated($actor, 'employee data changed');
            }

            $salaryBefore = array_intersect_key($current, array_flip(self::SALARY_FIELDS));
            $salaryAfter = array_intersect_key($changed, array_flip(self::SALARY_FIELDS));
            if ($salaryAfter !== []) {
                $this->audit->record(
                    $actor,
                    'employee.salary_changed',
                    'employee',
                    $id,
                    array_intersect_key($salaryBefore, $salaryAfter),
                    $salaryAfter,
                );
            }
            $otherAfter = array_diff_key($changed, array_flip(self::SALARY_FIELDS));
            if ($otherAfter !== []) {
                $this->audit->record(
                    $actor,
                    'employee.updated',
                    'employee',
                    $id,
                    array_intersect_key($current, $otherAfter),
                    $otherAfter,
                );
            }

            return $this->get($actor, $id);
        });
    }

    /**
     * @param array<string, mixed> $employee row including id and manager_id
     */
    public function assertVisible(Principal $actor, array $employee): void
    {
        $managerId = $employee['manager_id'] === null ? null : (int) $employee['manager_id'];
        if (!EmployeeScope::for($actor)->allows((int) $employee['id'], $managerId)) {
            throw new Forbidden('You do not have access to this employee.', 'out_of_scope');
        }
    }

    /**
     * Managers can see their direct reports but not their pay.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function present(Principal $actor, array $row): array
    {
        if ($actor->role === Role::Manager && (int) $row['id'] !== $actor->employeeId) {
            foreach (self::SALARY_FIELDS as $field) {
                unset($row[$field]);
            }
        }

        return $row;
    }

    /**
     * @param array<string, scalar|null> $values
     */
    private function assertReferences(array $values, ?int $employeeId): void
    {
        $errors = [];
        if (isset($values['department_id']) && $this->departments->find((int) $values['department_id']) === null) {
            $errors[] = ['field' => 'department_id', 'message' => 'Department does not exist.'];
        }
        if (isset($values['manager_id'])) {
            $managerId = (int) $values['manager_id'];
            $manager = $this->employees->find($managerId);
            if ($manager === null) {
                $errors[] = ['field' => 'manager_id', 'message' => 'Manager does not exist.'];
            } elseif ($manager['status'] !== 'active') {
                $errors[] = ['field' => 'manager_id', 'message' => 'Manager must be an active employee.'];
            } elseif ($employeeId !== null && $this->employees->wouldCreateCycle($employeeId, $managerId)) {
                $errors[] = ['field' => 'manager_id', 'message' => 'This reporting line would create a cycle.'];
            }
        }
        if ($errors !== []) {
            throw new ValidationFailed($errors);
        }
    }
}
