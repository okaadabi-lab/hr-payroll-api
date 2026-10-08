<?php

declare(strict_types=1);

namespace HrPayroll\Auth;

use HrPayroll\Domain\Exception\Forbidden;
use HrPayroll\Domain\Role;

/**
 * The authenticated user making the current request.
 */
final class Principal
{
    public function __construct(
        public readonly int $userId,
        public readonly Role $role,
        public readonly ?int $employeeId,
    ) {
    }

    public function hasRole(Role ...$roles): bool
    {
        return in_array($this->role, $roles, true);
    }

    public function requireRole(Role ...$roles): void
    {
        if (!$this->hasRole(...$roles)) {
            throw new Forbidden('Your role is not allowed to perform this action.');
        }
    }

    public function requireEmployee(): int
    {
        if ($this->employeeId === null) {
            throw new Forbidden('This action requires a user account linked to an employee.');
        }

        return $this->employeeId;
    }
}
