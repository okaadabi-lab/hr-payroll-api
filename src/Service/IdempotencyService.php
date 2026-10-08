<?php

declare(strict_types=1);

namespace HrPayroll\Service;

use HrPayroll\Auth\Principal;
use HrPayroll\Domain\Exception\BadRequest;
use HrPayroll\Domain\Exception\Conflict;
use HrPayroll\Domain\Exception\ValidationFailed;
use HrPayroll\Http\Json;
use HrPayroll\Infrastructure\Clock;
use HrPayroll\Infrastructure\Database;
use HrPayroll\Repository\IdempotencyRepository;
use PDOException;

/**
 * Runs an operation at most once per (user, Idempotency-Key).
 *
 * The key is reserved, the operation runs and the response is stored in one
 * transaction. If the operation fails, everything rolls back including the
 * key, so the client can retry with the same key. Only successful responses
 * are replayed.
 */
final class IdempotencyService
{
    public function __construct(
        private readonly Database $db,
        private readonly IdempotencyRepository $keys,
        private readonly Clock $clock,
    ) {
    }

    public static function requestHash(string $method, string $path, mixed $body): string
    {
        return hash('sha256', $method . ' ' . $path . "\n" . Json::encode(['body' => self::canonical($body)]));
    }

    /**
     * @param callable(): array{0: int, 1: array<string, mixed>} $operation returns status and body
     * @return array{status: int, body: array<string, mixed>, replayed: bool}
     */
    public function run(Principal $actor, ?string $key, string $requestHash, callable $operation): array
    {
        if ($key === null || preg_match('/^[\x21-\x7E]{8,255}$/', $key) !== 1) {
            throw new BadRequest(
                'An Idempotency-Key header of 8 to 255 visible ASCII characters is required.',
                'idempotency_key_required',
            );
        }

        try {
            return $this->db->transactional(function () use ($actor, $key, $requestHash, $operation): array {
                try {
                    $now = $this->clock->now()->format(Clock::DB_FORMAT);
                    $this->keys->reserve($actor->userId, $key, $requestHash, $now);
                } catch (PDOException $e) {
                    if ($e->getCode() === '23000') {
                        throw new IdempotencyKeyTaken();
                    }
                    throw $e;
                }
                [$status, $body] = $operation();
                $this->keys->complete($actor->userId, $key, $status, Json::encode($body));

                return ['status' => $status, 'body' => $body, 'replayed' => false];
            });
        } catch (IdempotencyKeyTaken) {
            return $this->replay($actor, $key, $requestHash);
        }
    }

    /**
     * @return array{status: int, body: array<string, mixed>, replayed: bool}
     */
    private function replay(Principal $actor, string $key, string $requestHash): array
    {
        $stored = $this->keys->find($actor->userId, $key);
        if ($stored === null || $stored['response_status'] === null) {
            throw new Conflict('A request with this Idempotency-Key is still in progress.', 'idempotency_in_progress');
        }
        if (!hash_equals((string) $stored['request_hash'], $requestHash)) {
            throw ValidationFailed::single(
                'Idempotency-Key',
                'This Idempotency-Key was already used with a different request payload.',
            );
        }
        /** @var array<string, mixed> $body */
        $body = json_decode((string) $stored['response_body'], true, 512, JSON_THROW_ON_ERROR);

        return ['status' => (int) $stored['response_status'], 'body' => $body, 'replayed' => true];
    }

    private static function canonical(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (!array_is_list($value)) {
            ksort($value);
        }

        return array_map(self::canonical(...), $value);
    }
}
