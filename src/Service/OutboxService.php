<?php

declare(strict_types=1);

namespace HrPayroll\Service;

use HrPayroll\Infrastructure\Clock;
use HrPayroll\Infrastructure\Database;
use HrPayroll\Repository\OutboxRepository;

/**
 * Pull-based delivery for integration clients. Delivery is at-least-once:
 * a consumer that crashes before acking will see the same event again, so it
 * must de-duplicate on the event id.
 */
final class OutboxService
{
    public const MAX_LIMIT = 100;

    public function __construct(
        private readonly Database $db,
        private readonly OutboxRepository $outbox,
        private readonly Clock $clock,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function pending(int $afterId, int $limit): array
    {
        $events = $this->outbox->pending($afterId, $limit);
        $last = $events === [] ? $afterId : (int) $events[array_key_last($events)]['id'];

        return [
            'data' => $events,
            'meta' => ['next_after' => $last, 'pending_total' => $this->outbox->countPending()],
        ];
    }

    /**
     * @param list<int> $ids
     * @return array{acked: list<int>, already_acked: list<int>, unknown: list<int>}
     */
    public function ack(int $clientId, array $ids): array
    {
        return $this->db->transactional(
            fn (): array => $this->outbox->ack($ids, $clientId, $this->clock->now()->format(Clock::DB_FORMAT)),
        );
    }
}
