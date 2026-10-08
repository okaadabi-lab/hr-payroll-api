<?php

declare(strict_types=1);

namespace HrPayroll\Repository;

use HrPayroll\Query\ListQuery;
use HrPayroll\Query\Page;
use HrPayroll\Infrastructure\Database;

final class PayrollPeriodRepository
{
    public const SORTABLE = ['period' => 'p.period', 'status' => 'p.status', 'created_at' => 'p.created_at'];
    public const FILTERS = ['status', 'year'];

    private const COLUMNS = 'p.id, p.period, p.status, p.created_by, p.calculated_by, p.calculated_at,
        p.calculation_note, p.approved_by, p.approved_at, p.paid_by, p.paid_at, p.created_at, p.updated_at,
        (SELECT COUNT(*) FROM payslips s WHERE s.payroll_period_id = p.id) AS payslip_count,
        (SELECT CAST(COALESCE(SUM(s.net_pay), 0) AS SIGNED) FROM payslips s
         WHERE s.payroll_period_id = p.id) AS total_net_pay';

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
        if (($status = $query->filter('status')) !== null) {
            $where .= ' AND p.status = :status';
            $params['status'] = $status;
        }
        if (($year = $query->filter('year')) !== null) {
            $where .= ' AND p.period LIKE :year';
            $params['year'] = (int) $year . '-%';
        }

        $total = $this->db->fetchInt("SELECT COUNT(*) FROM payroll_periods p WHERE {$where}", $params);
        $rows = $this->db->fetchAll(
            'SELECT ' . self::COLUMNS . " FROM payroll_periods p WHERE {$where} "
            . $query->orderBySql('p.id DESC') . " LIMIT {$query->perPage} OFFSET {$query->offset()}",
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
            'SELECT ' . self::COLUMNS . ' FROM payroll_periods p WHERE p.id = ?' . ($forUpdate ? ' FOR UPDATE' : ''),
            [$id],
        );
    }

    public function existsForPeriod(string $period): bool
    {
        return $this->db->fetchOne('SELECT id FROM payroll_periods WHERE period = ?', [$period]) !== null;
    }

    public function create(string $period, int $createdBy): int
    {
        return $this->db->insert('payroll_periods', ['period' => $period, 'created_by' => $createdBy]);
    }

    /**
     * @return array{id: int, status: string}|null
     */
    public function findByMonthForUpdate(string $period): ?array
    {
        $row = $this->db->fetchOne('SELECT id, status FROM payroll_periods WHERE period = ? FOR UPDATE', [$period]);

        return $row === null ? null : ['id' => (int) $row['id'], 'status' => (string) $row['status']];
    }

    public function markCalculated(int $id, int $userId, string $at, ?string $note): void
    {
        $this->db->run(
            "UPDATE payroll_periods
             SET status = 'calculated', calculated_by = ?, calculated_at = ?, calculation_note = ?
             WHERE id = ?",
            [$userId, $at, $note, $id],
        );
    }

    public function resetToDraft(int $id): void
    {
        $this->db->run("UPDATE payroll_periods SET status = 'draft' WHERE id = ?", [$id]);
    }

    public function lockedFrom(string $fromMonth): ?string
    {
        $row = $this->db->fetchOne(
            "SELECT period FROM payroll_periods WHERE status IN ('approved', 'paid') AND period >= ?
             ORDER BY period LIMIT 1 LOCK IN SHARE MODE",
            [$fromMonth],
        );

        return $row === null ? null : (string) $row['period'];
    }

    /**
     * @return list<int> ids of calculated periods from $fromMonth onwards, locked for update
     */
    public function calculatedFrom(string $fromMonth): array
    {
        $rows = $this->db->fetchAll(
            "SELECT id FROM payroll_periods WHERE status = 'calculated' AND period >= ? ORDER BY id FOR UPDATE",
            [$fromMonth],
        );

        return array_map(static fn (array $r): int => (int) $r['id'], $rows);
    }

    public function markApproved(int $id, int $userId, string $at): void
    {
        $this->db->run(
            "UPDATE payroll_periods SET status = 'approved', approved_by = ?, approved_at = ? WHERE id = ?",
            [$userId, $at, $id],
        );
    }

    public function markPaid(int $id, int $userId, string $at): void
    {
        $this->db->run(
            "UPDATE payroll_periods SET status = 'paid', paid_by = ?, paid_at = ? WHERE id = ?",
            [$userId, $at, $id],
        );
    }
}
