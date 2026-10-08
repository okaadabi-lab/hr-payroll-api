<?php

declare(strict_types=1);

namespace HrPayroll\Repository;

use HrPayroll\Query\ListQuery;
use HrPayroll\Query\Page;
use HrPayroll\Infrastructure\Database;
use HrPayroll\Infrastructure\JsonCodec;

final class AuditLogRepository
{
    public const SORTABLE = ['created_at' => 'a.created_at', 'action' => 'a.action'];
    public const FILTERS = ['entity_type', 'entity_id', 'action', 'actor_user_id'];

    public function __construct(private readonly Database $db)
    {
    }

    /**
     * @param array<string, mixed>|null $before
     * @param array<string, mixed>|null $after
     */
    public function record(
        ?int $actorUserId,
        string $action,
        string $entityType,
        ?int $entityId,
        ?array $before,
        ?array $after,
        ?string $ip,
        string $at,
    ): void {
        $this->db->insert('audit_logs', [
            'actor_user_id' => $actorUserId,
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'before_data' => $before === null ? null : JsonCodec::encode($before),
            'after_data' => $after === null ? null : JsonCodec::encode($after),
            'ip' => $ip,
            'created_at' => $at,
        ]);
    }

    /**
     * @return Page<array<string, mixed>>
     */
    public function list(ListQuery $query): Page
    {
        $where = '1 = 1';
        $params = [];
        foreach (['entity_type', 'action'] as $name) {
            if (($value = $query->filter($name)) !== null) {
                $where .= " AND a.{$name} = :{$name}";
                $params[$name] = $value;
            }
        }
        foreach (['entity_id', 'actor_user_id'] as $name) {
            if (($value = $query->filter($name)) !== null) {
                $where .= " AND a.{$name} = :{$name}";
                $params[$name] = (int) $value;
            }
        }

        $total = $this->db->fetchInt("SELECT COUNT(*) FROM audit_logs a WHERE {$where}", $params);
        $rows = $this->db->fetchAll(
            "SELECT a.id, a.actor_user_id, u.email AS actor_email, a.action, a.entity_type, a.entity_id,
                    a.before_data, a.after_data, a.ip, a.created_at
             FROM audit_logs a LEFT JOIN users u ON u.id = a.actor_user_id
             WHERE {$where} " . $query->orderBySql('a.id DESC')
            . " LIMIT {$query->perPage} OFFSET {$query->offset()}",
            $params,
        );
        foreach ($rows as &$row) {
            foreach (['before_data', 'after_data'] as $column) {
                $row[$column] = is_string($row[$column]) ? json_decode($row[$column], true) : null;
            }
        }

        return new Page($rows, $total);
    }
}
