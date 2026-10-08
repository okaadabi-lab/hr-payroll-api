<?php

declare(strict_types=1);

namespace HrPayroll\Repository;

use HrPayroll\Http\Json;
use HrPayroll\Infrastructure\Database;

final class OutboxRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function append(
        string $type,
        string $aggregateType,
        int $aggregateId,
        array $payload,
        string $occurredAt,
    ): int {
        return $this->db->insert('outbox_events', [
            'event_type' => $type,
            'aggregate_type' => $aggregateType,
            'aggregate_id' => $aggregateId,
            'payload' => Json::encode($payload),
            'occurred_at' => $occurredAt,
        ]);
    }

    /**
     * Unacknowledged events with id > $afterId, oldest first.
     *
     * @return list<array<string, mixed>>
     */
    public function pending(int $afterId, int $limit): array
    {
        $rows = $this->db->fetchAll(
            "SELECT id, event_type, payload, occurred_at FROM outbox_events
             WHERE acked_at IS NULL AND id > ? ORDER BY id LIMIT {$limit}",
            [$afterId],
        );

        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'type' => (string) $row['event_type'],
            'occurred_at' => (string) $row['occurred_at'],
            'payload' => json_decode((string) $row['payload'], true),
        ], $rows);
    }

    public function countPending(): int
    {
        return $this->db->fetchInt('SELECT COUNT(*) FROM outbox_events WHERE acked_at IS NULL');
    }

    /**
     * @param list<int> $ids
     * @return array{acked: list<int>, already_acked: list<int>, unknown: list<int>}
     */
    public function ack(array $ids, int $clientId, string $at): array
    {
        [$in, $params] = ScopeSql::in('id', $ids);
        $rows = $this->db->fetchAll("SELECT id, acked_at FROM outbox_events WHERE id IN ({$in}) FOR UPDATE", $params);

        $known = [];
        $toAck = [];
        foreach ($rows as $row) {
            $id = (int) $row['id'];
            $known[$id] = true;
            if ($row['acked_at'] === null) {
                $toAck[] = $id;
            }
        }
        if ($toAck !== []) {
            [$ackIn, $ackParams] = ScopeSql::in('ack', $toAck);
            $this->db->run(
                "UPDATE outbox_events SET acked_at = :acked_at, acked_by_client_id = :client WHERE id IN ({$ackIn})",
                [...$ackParams, 'acked_at' => $at, 'client' => $clientId],
            );
        }

        return [
            'acked' => $toAck,
            'already_acked' => array_values(array_diff(array_keys($known), $toAck)),
            'unknown' => array_values(array_diff($ids, array_keys($known))),
        ];
    }
}
