<?php

declare(strict_types=1);

namespace HrPayroll\Repository;

use HrPayroll\Query\ListQuery;
use HrPayroll\Query\Page;
use HrPayroll\Infrastructure\Database;

final class DepartmentRepository
{
    public const SORTABLE = ['name' => 'd.name', 'code' => 'd.code', 'created_at' => 'd.created_at'];

    private const COLUMNS = 'd.id, d.code, d.name, d.created_at, d.updated_at,
        (SELECT COUNT(*) FROM employees e WHERE e.department_id = d.id AND e.status = \'active\') AS active_employees';

    public function __construct(private readonly Database $db)
    {
    }

    /**
     * @return Page<array<string, mixed>>
     */
    public function list(ListQuery $query): Page
    {
        $where = '1 = 1';
        $params = [];
        if (($search = $query->filter('search')) !== null) {
            $where .= ' AND (d.name LIKE :search OR d.code LIKE :search_code)';
            $params['search'] = '%' . addcslashes($search, '%_\\') . '%';
            $params['search_code'] = $params['search'];
        }

        $total = $this->db->fetchInt("SELECT COUNT(*) FROM departments d WHERE {$where}", $params);
        $rows = $this->db->fetchAll(
            'SELECT ' . self::COLUMNS . " FROM departments d WHERE {$where} "
            . $query->orderBySql('d.id ASC') . " LIMIT {$query->perPage} OFFSET {$query->offset()}",
            $params,
        );

        return new Page($rows, $total);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array
    {
        return $this->db->fetchOne('SELECT ' . self::COLUMNS . ' FROM departments d WHERE d.id = ?', [$id]);
    }

    public function create(string $code, string $name): int
    {
        return $this->db->insert('departments', ['code' => $code, 'name' => $name]);
    }

    public function update(int $id, string $code, string $name): void
    {
        $this->db->run('UPDATE departments SET code = ?, name = ? WHERE id = ?', [$code, $name, $id]);
    }

    public function existsWithCodeOrName(string $code, string $name, ?int $exceptId = null): bool
    {
        return $this->db->fetchOne(
            'SELECT id FROM departments WHERE (code = ? OR name = ?) AND id <> ?',
            [$code, $name, $exceptId ?? 0],
        ) !== null;
    }
}
