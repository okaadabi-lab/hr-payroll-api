<?php

declare(strict_types=1);

namespace HrPayroll\Repository;

use HrPayroll\Auth\EmployeeScope;
use HrPayroll\Domain\Payroll\Payslip;
use HrPayroll\Http\ListQuery;
use HrPayroll\Http\Page;
use HrPayroll\Infrastructure\Database;

final class PayslipRepository
{
    public const SORTABLE = [
        'employee' => 's.employee_name',
        'net_pay' => 's.net_pay',
        'period' => 'p.period',
        'employee_number' => 's.employee_number',
    ];
    public const FILTERS = ['department', 'search', 'period'];

    private const COLUMNS = 's.id, s.payroll_period_id, p.period, p.status AS period_status, s.employee_id,
        s.employee_number, s.employee_name, s.department_name, s.gross_pay, s.total_deductions, s.net_pay,
        s.employer_contributions, s.created_at';

    private const FROM = 'payslips s
        JOIN payroll_periods p ON p.id = s.payroll_period_id
        JOIN employees e ON e.id = s.employee_id';

    public function __construct(private readonly Database $db)
    {
    }

    /**
     * @param list<string>|null $visibleStatuses period statuses the caller may see, null for all
     * @return Page<array<string, mixed>>
     */
    public function list(ListQuery $query, EmployeeScope $scope, ?int $periodId, ?array $visibleStatuses): Page
    {
        [$where, $params] = ScopeSql::condition($scope, 'e');
        if ($periodId !== null) {
            $where .= ' AND s.payroll_period_id = :period_id';
            $params['period_id'] = $periodId;
        }
        if (($period = $query->filter('period')) !== null) {
            $where .= ' AND p.period = :period';
            $params['period'] = $period;
        }
        if ($visibleStatuses !== null) {
            [$in, $inParams] = ScopeSql::in('status', $visibleStatuses);
            $where .= " AND p.status IN ({$in})";
            $params += $inParams;
        }
        if (($department = $query->filter('department')) !== null) {
            $where .= ' AND s.department_name = :department';
            $params['department'] = $department;
        }
        if (($search = $query->filter('search')) !== null) {
            $where .= ' AND s.employee_name LIKE :search';
            $params['search'] = '%' . addcslashes($search, '%_\\') . '%';
        }

        $total = $this->db->fetchInt('SELECT COUNT(*) FROM ' . self::FROM . " WHERE {$where}", $params);
        $rows = $this->db->fetchAll(
            'SELECT ' . self::COLUMNS . ' FROM ' . self::FROM . " WHERE {$where} "
            . $query->orderBySql('s.id ASC') . " LIMIT {$query->perPage} OFFSET {$query->offset()}",
            $params,
        );

        return new Page($rows, $total);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array
    {
        $row = $this->db->fetchOne(
            'SELECT ' . self::COLUMNS . ', e.manager_id FROM ' . self::FROM . ' WHERE s.id = ?',
            [$id],
        );
        if ($row === null) {
            return null;
        }
        $row['lines'] = $this->db->fetchAll(
            'SELECT line_type AS type, code, label, amount, basis FROM payslip_lines
             WHERE payslip_id = ? ORDER BY sort_order',
            [$id],
        );

        return $row;
    }

    public function deleteForPeriod(int $periodId): void
    {
        $this->db->run('DELETE FROM payslips WHERE payroll_period_id = ?', [$periodId]);
    }

    /**
     * @param array{employee_number: string, employee_name: string, department_name: string} $snapshot
     */
    public function store(int $periodId, Payslip $payslip, array $snapshot): int
    {
        $id = $this->db->insert('payslips', [
            'payroll_period_id' => $periodId,
            'employee_id' => $payslip->employeeId,
            'employee_number' => $snapshot['employee_number'],
            'employee_name' => $snapshot['employee_name'],
            'department_name' => $snapshot['department_name'],
            'gross_pay' => $payslip->grossPay(),
            'total_deductions' => $payslip->totalDeductions(),
            'net_pay' => $payslip->netPay(),
            'employer_contributions' => $payslip->employerCost() - $payslip->grossPay(),
        ]);
        foreach ($payslip->lines as $order => $line) {
            $this->db->insert('payslip_lines', [
                'payslip_id' => $id,
                'line_type' => $line->type->value,
                'code' => $line->code,
                'label' => $line->label,
                'amount' => $line->amount,
                'basis' => $line->basis,
                'sort_order' => $order,
            ]);
        }

        return $id;
    }

    /**
     * @return array{employees: int, gross_pay: int, total_deductions: int, net_pay: int, employer_contributions: int}
     */
    public function totalsForPeriod(int $periodId): array
    {
        $row = $this->db->fetchOne(
            'SELECT COUNT(*) AS employees, COALESCE(SUM(gross_pay), 0) AS gross_pay,
                    COALESCE(SUM(total_deductions), 0) AS total_deductions, COALESCE(SUM(net_pay), 0) AS net_pay,
                    COALESCE(SUM(employer_contributions), 0) AS employer_contributions
             FROM payslips WHERE payroll_period_id = ?',
            [$periodId],
        ) ?? [];

        return [
            'employees' => (int) ($row['employees'] ?? 0),
            'gross_pay' => (int) ($row['gross_pay'] ?? 0),
            'total_deductions' => (int) ($row['total_deductions'] ?? 0),
            'net_pay' => (int) ($row['net_pay'] ?? 0),
            'employer_contributions' => (int) ($row['employer_contributions'] ?? 0),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function forOutbox(int $periodId): array
    {
        return $this->db->fetchAll(
            'SELECT s.id, s.employee_id, s.employee_name, e.phone, s.net_pay
             FROM payslips s JOIN employees e ON e.id = s.employee_id
             WHERE s.payroll_period_id = ? ORDER BY s.id',
            [$periodId],
        );
    }
}
