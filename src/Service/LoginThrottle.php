<?php

declare(strict_types=1);

namespace HrPayroll\Service;

use HrPayroll\Domain\Exception\TooManyRequests;
use HrPayroll\Infrastructure\Clock;
use HrPayroll\Repository\LoginAttemptRepository;

/**
 * Sliding-window limit on failed logins per (email, IP). Stored in the
 * database so it holds across PHP processes without extra infrastructure.
 */
final class LoginThrottle
{
    public function __construct(
        private readonly LoginAttemptRepository $attempts,
        private readonly Clock $clock,
        private readonly int $maxAttempts,
        private readonly int $windowSeconds,
    ) {
    }

    public static function key(string $email, ?string $ip): string
    {
        return hash('sha256', strtolower($email) . '|' . ($ip ?? 'unknown'));
    }

    public function assertNotLimited(string $key): void
    {
        $now = $this->clock->now();
        $windowStart = $now->modify("-{$this->windowSeconds} seconds");
        $recent = $this->attempts->since($key, $windowStart->format(Clock::DB_FORMAT));
        if ($recent['count'] < $this->maxAttempts) {
            return;
        }

        $oldest = $recent['oldest'] === null ? $now->getTimestamp() : strtotime($recent['oldest'] . ' UTC');
        $retryAfter = max(1, (int) $oldest + $this->windowSeconds - $now->getTimestamp());

        throw new TooManyRequests('Too many failed login attempts. Try again later.', $retryAfter);
    }

    public function recordFailure(string $key): void
    {
        $now = $this->clock->now();
        $this->attempts->record($key, $now->format(Clock::DB_FORMAT));
        $staleBefore = $now->modify("-{$this->windowSeconds} seconds")->modify('-1 day');
        $this->attempts->pruneOlderThan($staleBefore->format(Clock::DB_FORMAT));
    }

    public function clear(string $key): void
    {
        $this->attempts->clear($key);
    }
}
