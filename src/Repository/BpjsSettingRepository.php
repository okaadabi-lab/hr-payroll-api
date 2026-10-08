<?php

declare(strict_types=1);

namespace HrPayroll\Repository;

use HrPayroll\Domain\Payroll\BpjsProgram;
use HrPayroll\Domain\Payroll\BpjsRate;
use HrPayroll\Infrastructure\Database;

final class BpjsSettingRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    /**
     * The newest setting per program that is already effective on $date.
     *
     * @return array<string, BpjsRate>
     */
    public function effectiveOn(string $date): array
    {
        $rows = $this->db->fetchAll(
            'SELECT s.program, s.employee_rate_bp, s.employer_rate_bp, s.wage_cap
             FROM bpjs_settings s
             WHERE s.effective_from = (
                SELECT MAX(i.effective_from) FROM bpjs_settings i
                WHERE i.program = s.program AND i.effective_from <= ?
             )',
            [$date],
        );
        $rates = [];
        foreach ($rows as $row) {
            $program = BpjsProgram::from((string) $row['program']);
            $rates[$program->value] = new BpjsRate(
                $program,
                (int) $row['employee_rate_bp'],
                (int) $row['employer_rate_bp'],
                $row['wage_cap'] === null ? null : (int) $row['wage_cap'],
            );
        }

        return $rates;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function all(): array
    {
        return $this->db->fetchAll(
            'SELECT id, program, employee_rate_bp, employer_rate_bp, wage_cap, effective_from, created_at
             FROM bpjs_settings ORDER BY program, effective_from DESC',
        );
    }

    public function existsFor(string $program, string $effectiveFrom): bool
    {
        return $this->db->fetchOne(
            'SELECT id FROM bpjs_settings WHERE program = ? AND effective_from = ?',
            [$program, $effectiveFrom],
        ) !== null;
    }

    public function create(string $program, int $employeeBp, int $employerBp, ?int $wageCap, string $effectiveFrom): int
    {
        return $this->db->insert('bpjs_settings', [
            'program' => $program,
            'employee_rate_bp' => $employeeBp,
            'employer_rate_bp' => $employerBp,
            'wage_cap' => $wageCap,
            'effective_from' => $effectiveFrom,
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array
    {
        return $this->db->fetchOne(
            'SELECT id, program, employee_rate_bp, employer_rate_bp, wage_cap, effective_from, created_at
             FROM bpjs_settings WHERE id = ?',
            [$id],
        );
    }
}
