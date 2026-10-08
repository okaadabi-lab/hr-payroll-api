<?php

declare(strict_types=1);

namespace HrPayroll\Repository;

use HrPayroll\Infrastructure\Database;

final class RefreshTokenRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    public function create(int $userId, string $familyId, string $tokenHash, string $expiresAt, ?string $ip): void
    {
        $this->db->insert('refresh_tokens', [
            'user_id' => $userId,
            'family_id' => $familyId,
            'token_hash' => $tokenHash,
            'expires_at' => $expiresAt,
            'created_ip' => $ip,
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByHashForUpdate(string $tokenHash): ?array
    {
        return $this->db->fetchOne(
            'SELECT id, user_id, family_id, expires_at, used_at, revoked_at
             FROM refresh_tokens WHERE token_hash = ? FOR UPDATE',
            [$tokenHash],
        );
    }

    public function markUsed(int $id, string $at): void
    {
        $this->db->run('UPDATE refresh_tokens SET used_at = ? WHERE id = ?', [$at, $id]);
    }

    public function revokeFamily(string $familyId, string $at): void
    {
        $this->db->run(
            'UPDATE refresh_tokens SET revoked_at = ? WHERE family_id = ? AND revoked_at IS NULL',
            [$at, $familyId],
        );
    }
}
