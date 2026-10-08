<?php

declare(strict_types=1);

namespace HrPayroll\Repository;

use HrPayroll\Infrastructure\Database;

final class IdempotencyRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    /**
     * Inserts the key with no response yet. A concurrent request with the same
     * key blocks on the unique index until this transaction ends, then fails
     * with a duplicate-key error.
     */
    public function reserve(int $userId, string $key, string $requestHash, string $at): void
    {
        $this->db->insert('idempotency_keys', [
            'user_id' => $userId,
            'idempotency_key' => $key,
            'request_hash' => $requestHash,
            'created_at' => $at,
        ]);
    }

    public function complete(int $userId, string $key, int $status, string $body): void
    {
        $this->db->run(
            'UPDATE idempotency_keys SET response_status = ?, response_body = ?
             WHERE user_id = ? AND idempotency_key = ?',
            [$status, $body, $userId, $key],
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(int $userId, string $key): ?array
    {
        return $this->db->fetchOne(
            'SELECT request_hash, response_status, response_body FROM idempotency_keys
             WHERE user_id = ? AND idempotency_key = ?',
            [$userId, $key],
        );
    }
}
