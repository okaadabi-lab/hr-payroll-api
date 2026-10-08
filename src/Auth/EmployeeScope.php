<?php

declare(strict_types=1);

namespace HrPayroll\Auth;

use HrPayroll\Domain\Role;

/**
 * Which employees' records a principal may read.
 * Admin and HR see everyone, a manager sees themself and their direct reports,
 * everyone else sees only themself.
 */
final class EmployeeScope
{
    public const ALL = 'all';
    public const TEAM = 'team';
    public const SELF = 'self';

    private function __construct(public readonly string $kind, public readonly ?int $employeeId)
    {
    }

    public static function for(Principal $principal): self
    {
        if ($principal->role->isHrOrAdmin()) {
            return new self(self::ALL, null);
        }

        $employeeId = $principal->requireEmployee();

        return $principal->role === Role::Manager
            ? new self(self::TEAM, $employeeId)
            : new self(self::SELF, $employeeId);
    }

    public static function self(int $employeeId): self
    {
        return new self(self::SELF, $employeeId);
    }

    public function allows(int $employeeId, ?int $managerId): bool
    {
        return match ($this->kind) {
            self::ALL => true,
            self::TEAM => $employeeId === $this->employeeId || $managerId === $this->employeeId,
            default => $employeeId === $this->employeeId,
        };
    }
}
