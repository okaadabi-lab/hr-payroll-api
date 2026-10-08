<?php

declare(strict_types=1);

namespace HrPayroll\Service;

use HrPayroll\Auth\Principal;
use HrPayroll\Domain\Exception\Conflict;
use HrPayroll\Domain\Exception\NotFound;
use HrPayroll\Domain\Exception\ValidationFailed;
use HrPayroll\Domain\Role;
use HrPayroll\Infrastructure\Database;
use HrPayroll\Repository\EmployeeRepository;
use HrPayroll\Repository\UserRepository;

final class UserService
{
    public function __construct(
        private readonly Database $db,
        private readonly UserRepository $users,
        private readonly EmployeeRepository $employees,
        private readonly AuditLogger $audit,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function me(Principal $principal): array
    {
        $user = $this->users->findById($principal->userId) ?? throw new NotFound('User not found.');
        $user['employee'] = $principal->employeeId === null ? null : $this->employees->find($principal->employeeId);

        return $user;
    }

    /**
     * @return array<string, mixed>
     */
    public function create(Principal $actor, string $email, string $password, Role $role, ?int $employeeId): array
    {
        $actor->requireRole(Role::Admin);
        if (!$role->isHrOrAdmin() && $employeeId === null) {
            throw ValidationFailed::single('employee_id', 'Manager and employee accounts need a linked employee.');
        }

        return $this->db->transactional(function () use ($actor, $email, $password, $role, $employeeId): array {
            if ($employeeId !== null && $this->employees->find($employeeId) === null) {
                throw ValidationFailed::single('employee_id', 'Employee does not exist.');
            }
            if ($this->users->emailExists($email)) {
                throw new Conflict('A user with this email already exists.', 'duplicate_user');
            }
            if ($employeeId !== null && $this->users->employeeHasAccount($employeeId)) {
                throw new Conflict('This employee already has a user account.', 'duplicate_user');
            }
            $id = $this->users->create($email, password_hash($password, PASSWORD_DEFAULT), $role->value, $employeeId);
            $this->audit->record($actor, 'user.created', 'user', $id, null, [
                'email' => $email,
                'role' => $role->value,
                'employee_id' => $employeeId,
            ]);

            return $this->users->findById($id) ?? [];
        });
    }
}
