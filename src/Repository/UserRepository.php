<?php

declare(strict_types=1);

namespace HrPayroll\Repository;

use HrPayroll\Infrastructure\Database;

final class UserRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByEmail(string $email): ?array
    {
        return $this->db->fetchOne(
            'SELECT id, email, password_hash, role, employee_id, is_active FROM users WHERE email = ?',
            [$email],
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findById(int $id): ?array
    {
        return $this->db->fetchOne(
            'SELECT id, email, role, employee_id, is_active, last_login_at, created_at FROM users WHERE id = ?',
            [$id],
        );
    }

    public function emailExists(string $email): bool
    {
        return $this->db->fetchOne('SELECT id FROM users WHERE email = ?', [$email]) !== null;
    }

    public function employeeHasAccount(int $employeeId): bool
    {
        return $this->db->fetchOne('SELECT id FROM users WHERE employee_id = ?', [$employeeId]) !== null;
    }

    public function create(string $email, string $passwordHash, string $role, ?int $employeeId): int
    {
        return $this->db->insert('users', [
            'email' => $email,
            'password_hash' => $passwordHash,
            'role' => $role,
            'employee_id' => $employeeId,
        ]);
    }

    public function updatePasswordHash(int $id, string $hash): void
    {
        $this->db->run('UPDATE users SET password_hash = ? WHERE id = ?', [$hash, $id]);
    }

    public function touchLastLogin(int $id, string $at): void
    {
        $this->db->run('UPDATE users SET last_login_at = ? WHERE id = ?', [$at, $id]);
    }
}
