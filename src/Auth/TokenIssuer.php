<?php

declare(strict_types=1);

namespace HrPayroll\Auth;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use HrPayroll\Domain\Exception\Unauthenticated;
use HrPayroll\Domain\Role;
use HrPayroll\Infrastructure\Clock;
use Throwable;

final class TokenIssuer
{
    private const ALGORITHM = 'HS256';

    public function __construct(
        private readonly string $secret,
        private readonly string $issuer,
        private readonly int $ttlSeconds,
        private readonly Clock $clock,
    ) {
    }

    public function ttlSeconds(): int
    {
        return $this->ttlSeconds;
    }

    public function issue(Principal $principal): string
    {
        $now = $this->clock->now()->getTimestamp();

        return JWT::encode([
            'iss' => $this->issuer,
            'sub' => (string) $principal->userId,
            'role' => $principal->role->value,
            'emp' => $principal->employeeId,
            'iat' => $now,
            'exp' => $now + $this->ttlSeconds,
            'jti' => bin2hex(random_bytes(8)),
        ], $this->secret, self::ALGORITHM);
    }

    public function verify(string $token): Principal
    {
        try {
            JWT::$timestamp = $this->clock->now()->getTimestamp();
            $claims = (array) JWT::decode($token, new Key($this->secret, self::ALGORITHM));
        } catch (Throwable) {
            throw new Unauthenticated('The access token is invalid or expired.', 'invalid_token');
        }

        $role = Role::tryFrom(is_string($claims['role'] ?? null) ? $claims['role'] : '');
        if (($claims['iss'] ?? null) !== $this->issuer || $role === null || !is_numeric($claims['sub'] ?? null)) {
            throw new Unauthenticated('The access token is invalid or expired.', 'invalid_token');
        }
        $employeeId = $claims['emp'] ?? null;

        return new Principal((int) $claims['sub'], $role, is_int($employeeId) ? $employeeId : null);
    }
}
