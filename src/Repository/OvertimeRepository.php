<?php

declare(strict_types=1);

namespace HrPayroll\Repository;

use HrPayroll\Auth\EmployeeScope;
use HrPayroll\Http\ListQuery;
use HrPayroll\Http\Page;
use HrPayroll\Infrastructure\Database;

final class OvertimeRepository
{
    public const SORTABLE = ['work_date' => 'o.work_date', 'created_at' => 'o.created_at', 'employee' => 'e.full_name'];
    public const FILTERS = ['employee_id', 'department_id', 'status', 'period', 'search'];

    private const COLUMNS = 'o.id, o.employee_id, e.full_name AS employee_name, e.manager_id, o.work_date,
        o.minutes, o.reason, o.status, o.decided_by, o.decided_at, o.decision_note, o.created_at, o.updated_at';

    private const FROM = 'overtime_requests o JOIN employees e ON e.id = o.employee_id';

    public function __construct(private readonly Database $db)
    {
    }

    /**
     * @param array{from?: string, to?: string} $range
     * @return Page<array<string, mixed>>
     */
    public function list(ListQuery $query, EmployeeScope $scope, array $range): Page
    {
        [$where, $params] = ScopeSql::condition($scope, 'e');
        if (($employee = $query->filter('employee_id')) !== null) {
            $where .= ' AND o.employee_id = :employee_id';
            $params['employee_id'] = (int) $employee;
        }
        if (($department = $query->filter('department_id')) !== null) {
            $where .= ' AND e.department_id = :department_id';
            $params['department_id'] = (int) $department;
        }
        if (($status = $query->filter('status')) !== null) {
            $where .= ' AND o.status = :status';
            $params['status'] = $status;
        }
        if (isset($range['from'], $range['to'])) {
            $where .= ' AND o.work_date BETWEEN :from_date AND :to_date';
            $params['from_date'] = $range['from'];
            $params['to_date'] = $range['to'];
        }
        if (($search = $query->filter('search')) !== null) {
            $where .= ' AND e.full_name LIKE :search';
            $params['search'] = '%' . addcslashes($search, '%_\\') . '%';
        }

        $total = $this->db->fetchInt('SELECT COUNT(*) FROM ' . self::FROM . " WHERE {$where}", $params);
        $rows = $this->db->fetchAll(
            'SELECT ' . self::COLUMNS . ' FROM ' . self::FROM . " WHERE {$where} "
            . $query->orderBySql('o.id ASC') . " LIMIT {$query->perPage} OFFSET {$query->offset()}",
            $params,
        );

        return new Page($rows, $total);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(int $id, bool $forUpdate = false): ?array
    {
        return $this->db->fetchOne(
            'SELECT ' . self::COLUMNS . ' FROM ' . self::FROM . ' WHERE o.id = ?' . ($forUpdate ? ' FOR UPDATE' : ''),
            [$id],
        );
    }

    /**
     * Pending and approved requests count towards the limits; rejected ones do not.
     *
     * @return list<array{work_date: string, minutes: int}>
     */
    public function activeBetween(int $employeeId, string $from, string $to): array
    {
        $rows = $this->db->fetchAll(
            "SELECT work_date, minutes FROM overtime_requests
             WHERE employee_id = ? AND work_date BETWEEN ? AND ? AND status IN ('pending', 'approved')",
            [$employeeId, $from, $to],
        );

        return array_map(
            static fn (array $r): array => ['work_date' => (string) $r['work_date'], 'minutes' => (int) $r['minutes']],
            $rows,
        );
    }

    public function create(int $employeeId, string $date, int $minutes, string $reason): int
    {
        return $this->db->insert('overtime_requests', [
            'employee_id' => $employeeId,
            'work_date' => $date,
            'minutes' => $minutes,
            'reason' => $reason,
        ]);
    }

    public function decide(int $id, string $status, int $decidedBy, string $at, ?string $note): void
    {
        $this->db->run(
            'UPDATE overtime_requests SET status = ?, decided_by = ?, decided_at = ?, decision_note = ? WHERE id = ?',
            [$status, $decidedBy, $at, $note, $id],
        );
    }

    /**
     * @return array<int, list<array{work_date: string, minutes: int}>> employee id => approved entries
     */
    public function approvedBetween(string $from, string $to): array
    {
        $rows = $this->db->fetchAll(
            "SELECT employee_id, work_date, minutes FROM overtime_requests
             WHERE status = 'approved' AND work_date BETWEEN ? AND ? ORDER BY employee_id, work_date, id",
            [$from, $to],
        );
        $byEmployee = [];
        foreach ($rows as $row) {
            $byEmployee[(int) $row['employee_id']][] = [
                'work_date' => (string) $row['work_date'],
                'minutes' => (int) $row['minutes'],
            ];
        }

        return $byEmployee;
    }
}
