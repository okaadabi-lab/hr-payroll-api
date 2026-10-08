<?php

declare(strict_types=1);

namespace HrPayroll\Repository;

use HrPayroll\Auth\EmployeeScope;
use HrPayroll\Query\ListQuery;
use HrPayroll\Query\Page;
use HrPayroll\Infrastructure\Database;

final class EmployeeRepository
{
    public const SORTABLE = [
        'name' => 'e.full_name',
        'employee_number' => 'e.employee_number',
        'hired_at' => 'e.hired_at',
        'base_salary' => 'e.base_salary',
        'created_at' => 'e.created_at',
    ];
    public const FILTERS = ['department_id', 'status', 'manager_id', 'search'];

    private const COLUMNS = 'e.id, e.employee_number, e.full_name, e.email, e.phone, e.position,
        e.department_id, d.name AS department_name, e.manager_id, m.full_name AS manager_name,
        e.base_salary, e.fixed_allowances, e.hired_at, e.status, e.created_at, e.updated_at';

    private const FROM = 'employees e
        JOIN departments d ON d.id = e.department_id
        LEFT JOIN employees m ON m.id = e.manager_id';

    public function __construct(private readonly Database $db)
    {
    }

    /**
     * @return Page<array<string, mixed>>
     */
    public function list(ListQuery $query, EmployeeScope $scope): Page
    {
        [$where, $params] = ScopeSql::condition($scope, 'e');
        if (($department = $query->filter('department_id')) !== null) {
            $where .= ' AND e.department_id = :department_id';
            $params['department_id'] = (int) $department;
        }
        if (($status = $query->filter('status')) !== null) {
            $where .= ' AND e.status = :status';
            $params['status'] = $status;
        }
        if (($manager = $query->filter('manager_id')) !== null) {
            $where .= ' AND e.manager_id = :manager_id';
            $params['manager_id'] = (int) $manager;
        }
        if (($search = $query->filter('search')) !== null) {
            $where .= ' AND e.full_name LIKE :search';
            $params['search'] = '%' . addcslashes($search, '%_\\') . '%';
        }

        $total = $this->db->fetchInt('SELECT COUNT(*) FROM ' . self::FROM . " WHERE {$where}", $params);
        $rows = $this->db->fetchAll(
            'SELECT ' . self::COLUMNS . ' FROM ' . self::FROM . " WHERE {$where} "
            . $query->orderBySql('e.id ASC') . " LIMIT {$query->perPage} OFFSET {$query->offset()}",
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
            'SELECT ' . self::COLUMNS . ' FROM ' . self::FROM . ' WHERE e.id = ?' . ($forUpdate ? ' FOR UPDATE' : ''),
            [$id],
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByNumber(string $employeeNumber): ?array
    {
        return $this->db->fetchOne(
            'SELECT id, manager_id, status FROM employees WHERE employee_number = ?',
            [$employeeNumber],
        );
    }

    public function numberOrEmailTaken(string $employeeNumber, string $email, int $exceptId = 0): bool
    {
        return $this->db->fetchOne(
            'SELECT id FROM employees WHERE (employee_number = ? OR email = ?) AND id <> ?',
            [$employeeNumber, $email, $exceptId],
        ) !== null;
    }

    /**
     * @param array<string, scalar|null> $values
     */
    public function create(array $values): int
    {
        return $this->db->insert('employees', $values);
    }

    /**
     * @param array<string, scalar|null> $values column => value, keys come from service code only
     */
    public function update(int $id, array $values): void
    {
        if ($values === []) {
            return;
        }
        $sets = implode(', ', array_map(static fn (string $c): string => "{$c} = :{$c}", array_keys($values)));
        $this->db->run("UPDATE employees SET {$sets} WHERE id = :id", [...$values, 'id' => $id]);
    }

    /**
     * True when $candidateManagerId is $employeeId or reports (directly or
     * indirectly) to it - assigning it as manager would create a cycle.
     */
    public function wouldCreateCycle(int $employeeId, int $candidateManagerId): bool
    {
        $current = $candidateManagerId;
        $guard = 0;
        while ($guard++ < 100) {
            if ($current === $employeeId) {
                return true;
            }
            $row = $this->db->fetchOne('SELECT manager_id FROM employees WHERE id = ?', [$current]);
            if ($row === null || $row['manager_id'] === null) {
                return false;
            }
            $current = (int) $row['manager_id'];
        }

        return true;
    }

    /**
     * Active employees with everything payroll needs, in a stable order.
     *
     * @return list<array<string, mixed>>
     */
    public function activeForPayroll(string $hiredOnOrBefore): array
    {
        return $this->db->fetchAll(
            "SELECT e.id, e.employee_number, e.full_name, e.phone, e.base_salary, e.fixed_allowances,
                    d.name AS department_name
             FROM employees e JOIN departments d ON d.id = e.department_id
             WHERE e.status = 'active' AND e.hired_at <= ?
             ORDER BY e.id",
            [$hiredOnOrBefore],
        );
    }
}
