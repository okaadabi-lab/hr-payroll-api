<?php

declare(strict_types=1);

namespace HrPayroll\Service;

use HrPayroll\Domain\Exception\Forbidden;
use HrPayroll\Domain\Exception\Unauthenticated;
use HrPayroll\Infrastructure\Clock;
use HrPayroll\Repository\ApiClientRepository;

/**
 * API keys look like "hrp_<12-char prefix>_<secret>". The prefix is stored in
 * clear for lookup; only a SHA-256 of the full key is stored and compared in
 * constant time.
 */
final class ApiKeyAuthenticator
{
    public function __construct(private readonly ApiClientRepository $clients, private readonly Clock $clock)
    {
    }

    /**
     * @return array{key: string, prefix: string, hash: string}
     */
    public static function generate(): array
    {
        $prefix = bin2hex(random_bytes(6));
        $key = 'hrp_' . $prefix . '_' . bin2hex(random_bytes(24));

        return ['key' => $key, 'prefix' => $prefix, 'hash' => hash('sha256', $key)];
    }

    /**
     * @return array{id: int, name: string, scopes: list<string>}
     */
    public function authenticate(?string $presentedKey, string $requiredScope): array
    {
        if ($presentedKey === null || preg_match('/^hrp_([0-9a-f]{12})_[0-9a-f]{48}$/', $presentedKey, $m) !== 1) {
            throw new Unauthenticated('A valid X-Api-Key header is required.', 'invalid_api_key');
        }
        $client = $this->clients->findActiveByPrefix($m[1]);
        if ($client === null || !hash_equals((string) $client['key_hash'], hash('sha256', $presentedKey))) {
            throw new Unauthenticated('A valid X-Api-Key header is required.', 'invalid_api_key');
        }

        $scopes = array_values(array_filter(array_map('trim', explode(' ', (string) $client['scopes']))));
        if (!in_array($requiredScope, $scopes, true)) {
            throw new Forbidden("This API key lacks the {$requiredScope} scope.", 'insufficient_scope');
        }
        $this->clients->touch((int) $client['id'], $this->clock->now()->format(Clock::DB_FORMAT));

        return ['id' => (int) $client['id'], 'name' => (string) $client['name'], 'scopes' => $scopes];
    }
}
