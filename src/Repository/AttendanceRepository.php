<?php

declare(strict_types=1);

namespace HrPayroll\Repository;

use HrPayroll\Auth\EmployeeScope;
use HrPayroll\Http\ListQuery;
use HrPayroll\Http\Page;
use HrPayroll\Infrastructure\Database;

final class AttendanceRepository
{
    public const SORTABLE = ['work_date' => 'a.work_date', 'employee' => 'e.full_name', 'status' => 'a.status'];
    public const FILTERS = ['employee_id', 'department_id', 'status', 'period', 'from', 'to', 'search'];

    private const COLUMNS = 'a.id, a.employee_id, e.full_name AS employee_name, e.department_id,
        a.work_date, a.status, a.clock_in, a.clock_out, a.note, a.created_at, a.updated_at';

    public function __construct(private readonly Database $db)
    {
    }

    /**
     * @param array{from?: string, to?: string} $range resolved date bounds
     * @return Page<array<string, mixed>>
     */
    public function list(ListQuery $query, EmployeeScope $scope, array $range): Page
    {
        [$where, $params] = ScopeSql::condition($scope, 'e');
        if (($employee = $query->filter('employee_id')) !== null) {
            $where .= ' AND a.employee_id = :employee_id';
            $params['employee_id'] = (int) $employee;
        }
        if (($department = $query->filter('department_id')) !== null) {
            $where .= ' AND e.department_id = :department_id';
            $params['department_id'] = (int) $department;
        }
        if (($status = $query->filter('status')) !== null) {
            $where .= ' AND a.status = :status';
            $params['status'] = $status;
        }
        if (isset($range['from'])) {
            $where .= ' AND a.work_date >= :from_date';
            $params['from_date'] = $range['from'];
        }
        if (isset($range['to'])) {
            $where .= ' AND a.work_date <= :to_date';
            $params['to_date'] = $range['to'];
        }
        if (($search = $query->filter('search')) !== null) {
            $where .= ' AND e.full_name LIKE :search';
            $params['search'] = '%' . addcslashes($search, '%_\\') . '%';
        }

        $from = 'attendance_records a JOIN employees e ON e.id = a.employee_id';
        $total = $this->db->fetchInt("SELECT COUNT(*) FROM {$from} WHERE {$where}", $params);
        $rows = $this->db->fetchAll(
            'SELECT ' . self::COLUMNS . " FROM {$from} WHERE {$where} "
            . $query->orderBySql('a.id ASC') . " LIMIT {$query->perPage} OFFSET {$query->offset()}",
            $params,
        );

        return new Page($rows, $total);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array
    {
        return $this->db->fetchOne(
            'SELECT ' . self::COLUMNS . ', e.manager_id FROM attendance_records a
             JOIN employees e ON e.id = a.employee_id WHERE a.id = ?',
            [$id],
        );
    }

    public function existsFor(int $employeeId, string $date): bool
    {
        return $this->db->fetchOne(
            'SELECT id FROM attendance_records WHERE employee_id = ? AND work_date = ?',
            [$employeeId, $date],
        ) !== null;
    }

    /**
     * @param array{
     *     employee_id: int,
     *     work_date: string,
     *     status: string,
     *     clock_in: ?string,
     *     clock_out: ?string,
     *     note: ?string
     * } $values
     */
    public function create(array $values): int
    {
        return $this->db->insert('attendance_records', $values);
    }

    /**
     * @param array{status: string, clock_in: ?string, clock_out: ?string, note: ?string} $values
     */
    public function update(int $id, array $values): void
    {
        $this->db->run(
            'UPDATE attendance_records SET status = :status, clock_in = :clock_in, clock_out = :clock_out, note = :note
             WHERE id = :id',
            [...$values, 'id' => $id],
        );
    }

    public function delete(int $id): void
    {
        $this->db->run('DELETE FROM attendance_records WHERE id = ?', [$id]);
    }

    /**
     * @return array<int, list<string>> employee id => dates with status "absent"
     */
    public function absentDatesBetween(string $from, string $to): array
    {
        $rows = $this->db->fetchAll(
            "SELECT employee_id, work_date FROM attendance_records
             WHERE status = 'absent' AND work_date BETWEEN ? AND ? ORDER BY employee_id, work_date",
            [$from, $to],
        );
        $byEmployee = [];
        foreach ($rows as $row) {
            $byEmployee[(int) $row['employee_id']][] = (string) $row['work_date'];
        }

        return $byEmployee;
    }
}
