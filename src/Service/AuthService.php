<?php

declare(strict_types=1);

namespace HrPayroll\Service;

use HrPayroll\Auth\Principal;
use HrPayroll\Auth\TokenIssuer;
use HrPayroll\Domain\Exception\Unauthenticated;
use HrPayroll\Domain\Role;
use HrPayroll\Infrastructure\Clock;
use HrPayroll\Infrastructure\Database;
use HrPayroll\Repository\RefreshTokenRepository;
use HrPayroll\Repository\UserRepository;

final class AuthService
{
    /** Verified against when the email is unknown so both paths cost the same. */
    private const DUMMY_HASH = '$2y$10$GFMO/bOitcFksXB3Bft.Geshd70rNOL13XHs1nR1zqu.J1hcgaJKK';

    public function __construct(
        private readonly Database $db,
        private readonly UserRepository $users,
        private readonly RefreshTokenRepository $refreshTokens,
        private readonly TokenIssuer $tokens,
        private readonly LoginThrottle $throttle,
        private readonly AuditLogger $audit,
        private readonly Clock $clock,
        private readonly int $refreshTtlSeconds,
    ) {
    }

    /**
     * @return array<string, mixed> token response
     */
    public function login(string $email, string $password, ?string $ip): array
    {
        $key = LoginThrottle::key($email, $ip);
        $this->throttle->assertNotLimited($key);

        $user = $this->users->findByEmail($email);
        $hash = $user === null ? self::DUMMY_HASH : (string) $user['password_hash'];
        $valid = password_verify($password, $hash);

        if ($user === null || !$valid || (int) $user['is_active'] !== 1) {
            $this->throttle->recordFailure($key);
            throw new Unauthenticated('Invalid email or password.', 'invalid_credentials');
        }

        $this->throttle->clear($key);
        $userId = (int) $user['id'];
        if (password_needs_rehash($hash, PASSWORD_DEFAULT)) {
            $this->users->updatePasswordHash($userId, password_hash($password, PASSWORD_DEFAULT));
        }

        $principal = new Principal(
            $userId,
            Role::from((string) $user['role']),
            $user['employee_id'] === null ? null : (int) $user['employee_id'],
        );

        return $this->db->transactional(function () use ($principal, $ip): array {
            $this->users->touchLastLogin($principal->userId, $this->clock->now()->format(Clock::DB_FORMAT));
            $this->audit->record($principal, 'auth.login', 'user', $principal->userId);

            return $this->issue($principal, bin2hex(random_bytes(16)), $ip);
        });
    }

    /**
     * Rotates a refresh token. Presenting a token that was already rotated
     * means it leaked (or a client retried badly): the whole family is revoked
     * so neither party can keep using it.
     *
     * @return array<string, mixed>
     */
    public function refresh(string $refreshToken, ?string $ip): array
    {
        $outcome = $this->db->transactional(function () use ($refreshToken, $ip): array {
            $row = $this->refreshTokens->findByHashForUpdate(self::hash($refreshToken));
            if ($row === null || $row['revoked_at'] !== null) {
                return ['error' => 'invalid_refresh_token'];
            }

            $now = $this->clock->now();
            if ($row['used_at'] !== null) {
                $this->refreshTokens->revokeFamily((string) $row['family_id'], $now->format(Clock::DB_FORMAT));
                $this->audit->record(null, 'auth.refresh_token_reused', 'user', (int) $row['user_id']);

                return ['error' => 'refresh_token_reused'];
            }
            if (strtotime($row['expires_at'] . ' UTC') <= $now->getTimestamp()) {
                return ['error' => 'invalid_refresh_token'];
            }

            $user = $this->users->findById((int) $row['user_id']);
            if ($user === null || (int) $user['is_active'] !== 1) {
                $this->refreshTokens->revokeFamily((string) $row['family_id'], $now->format(Clock::DB_FORMAT));

                return ['error' => 'invalid_refresh_token'];
            }

            $this->refreshTokens->markUsed((int) $row['id'], $now->format(Clock::DB_FORMAT));
            $principal = new Principal(
                (int) $user['id'],
                Role::from((string) $user['role']),
                $user['employee_id'] === null ? null : (int) $user['employee_id'],
            );

            return ['tokens' => $this->issue($principal, (string) $row['family_id'], $ip)];
        });

        // Raised after the transaction so a family revocation is committed, not rolled back.
        if (isset($outcome['error'])) {
            $message = $outcome['error'] === 'refresh_token_reused'
                ? 'This refresh token was already used. All sessions from this login were revoked.'
                : 'The refresh token is invalid or expired.';
            throw new Unauthenticated($message, (string) $outcome['error']);
        }

        return $outcome['tokens'];
    }

    public function logout(string $refreshToken): void
    {
        $this->db->transactional(function () use ($refreshToken): void {
            $row = $this->refreshTokens->findByHashForUpdate(self::hash($refreshToken));
            if ($row !== null) {
                $now = $this->clock->now()->format(Clock::DB_FORMAT);
                $this->refreshTokens->revokeFamily((string) $row['family_id'], $now);
            }
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function issue(Principal $principal, string $familyId, ?string $ip): array
    {
        $refreshToken = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $expiresAt = $this->clock->now()->modify("+{$this->refreshTtlSeconds} seconds");
        $this->refreshTokens->create(
            $principal->userId,
            $familyId,
            self::hash($refreshToken),
            $expiresAt->format(Clock::DB_FORMAT),
            $ip,
        );

        return [
            'token_type' => 'Bearer',
            'access_token' => $this->tokens->issue($principal),
            'expires_in' => $this->tokens->ttlSeconds(),
            'refresh_token' => $refreshToken,
            'refresh_expires_in' => $this->refreshTtlSeconds,
            'user' => [
                'id' => $principal->userId,
                'role' => $principal->role->value,
                'employee_id' => $principal->employeeId,
            ],
        ];
    }

    /**
     * Refresh tokens are 256-bit random values, so a fast hash is sufficient:
     * there is nothing to brute-force, we only avoid storing usable tokens.
     */
    private static function hash(string $token): string
    {
        return hash('sha256', $token);
    }
}
