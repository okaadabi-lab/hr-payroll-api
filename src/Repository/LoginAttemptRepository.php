<?php

declare(strict_types=1);

namespace HrPayroll\Repository;

use HrPayroll\Infrastructure\Database;

final class LoginAttemptRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    /**
     * @return array{count: int, oldest: string|null}
     */
    public function since(string $key, string $since): array
    {
        $row = $this->db->fetchOne(
            'SELECT COUNT(*) AS attempts, MIN(attempted_at) AS oldest
             FROM login_attempts WHERE throttle_key = ? AND attempted_at > ?',
            [$key, $since],
        );

        return [
            'count' => (int) ($row['attempts'] ?? 0),
            'oldest' => isset($row['oldest']) ? (string) $row['oldest'] : null,
        ];
    }

    public function record(string $key, string $at): void
    {
        $this->db->insert('login_attempts', ['throttle_key' => $key, 'attempted_at' => $at]);
    }

    public function clear(string $key): void
    {
        $this->db->run('DELETE FROM login_attempts WHERE throttle_key = ?', [$key]);
    }

    public function pruneOlderThan(string $before): void
    {
        $this->db->run('DELETE FROM login_attempts WHERE attempted_at < ?', [$before]);
    }
}
