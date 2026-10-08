<?php

declare(strict_types=1);

namespace HrPayroll\Repository;

use HrPayroll\Infrastructure\Database;

final class ApiClientRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findActiveByPrefix(string $prefix): ?array
    {
        return $this->db->fetchOne(
            'SELECT id, name, key_hash, scopes FROM api_clients WHERE key_prefix = ? AND is_active = 1',
            [$prefix],
        );
    }

    public function create(string $name, string $prefix, string $keyHash, string $scopes): int
    {
        return $this->db->insert('api_clients', [
            'name' => $name,
            'key_prefix' => $prefix,
            'key_hash' => $keyHash,
            'scopes' => $scopes,
        ]);
    }

    public function touch(int $id, string $at): void
    {
        $this->db->run('UPDATE api_clients SET last_used_at = ? WHERE id = ?', [$at, $id]);
    }
}
