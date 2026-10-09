<?php

declare(strict_types=1);

namespace HrPayroll\Infrastructure;

use HrPayroll\Repository\ApiClientRepository;
use HrPayroll\Service\ApiKeyAuthenticator;

/**
 * Fictional sample data: one company, three departments, a small reporting
 * line, 2026 public holidays and BPJS settings. Used by bin/seed and as the
 * shared fixture for integration tests.
 */
final class Seeder
{
    public const PASSWORD = 'Password123!';

    /** @var array<string, int> */
    public array $ids = [];

    public function __construct(private readonly Database $db)
    {
    }

    public function run(): void
    {
        $this->db->transactional(function (): void {
            $this->departments();
            $this->employees();
            $this->users();
            $this->holidays();
            $this->bpjs();
        });
    }

    /**
     * @return string the plain API key, shown once
     */
    public function createApiClient(string $name, string $scopes): string
    {
        $generated = ApiKeyAuthenticator::generate();
        (new ApiClientRepository($this->db))->create($name, $generated['prefix'], $generated['hash'], $scopes);

        return $generated['key'];
    }

    private function departments(): void
    {
        foreach ([['FIN', 'Finance'], ['OPS', 'Operations'], ['ENG', 'Engineering']] as [$code, $name]) {
            $id = $this->db->insert('departments', ['code' => $code, 'name' => $name]);
            $this->ids['d_' . strtolower($code)] = $id;
        }
    }

    private function employees(): void
    {
        $rows = [
            ['dina', 'EMP-001', 'Dina Kartika', '+62812000001', 'Ops Manager', 'd_ops', null, 15_000_000, 2_000_000],
            ['budi', 'EMP-002', 'Budi Santoso', '+62812000002', 'Supervisor', 'd_ops', 'dina', 7_500_000, 1_000_000],
            ['sari', 'EMP-003', 'Sari Wulandari', '+62812000003', 'Logistics', 'd_ops', 'dina', 5_500_000, 500_000],
            ['raka', 'EMP-004', 'Raka Pratama', '+62812000004', 'Eng Lead', 'd_eng', null, 18_000_000, 2_500_000],
            ['nina', 'EMP-005', 'Nina Lestari', '+62812000005', 'Developer', 'd_eng', 'raka', 12_000_000, 1_500_000],
            ['yoga', 'EMP-006', 'Yoga Firmansyah', null, 'Finance Officer', 'd_fin', null, 9_000_000, 750_000],
        ];
        foreach ($rows as [$key, $number, $name, $phone, $position, $dept, $manager, $base, $allowances]) {
            $this->ids['emp_' . $key] = $this->db->insert('employees', [
                'employee_number' => $number,
                'full_name' => $name,
                'email' => strtolower(str_replace(' ', '.', $name)) . '@example.test',
                'phone' => $phone,
                'position' => $position,
                'department_id' => $this->ids[$dept],
                'manager_id' => $manager === null ? null : $this->ids['emp_' . $manager],
                'base_salary' => $base,
                'fixed_allowances' => $allowances,
                'hired_at' => '2024-01-15',
            ]);
        }
    }

    private function users(): void
    {
        $hash = password_hash(self::PASSWORD, PASSWORD_DEFAULT);
        $rows = [
            ['admin', 'admin@example.test', 'admin', null],
            ['admin2', 'admin2@example.test', 'admin', null],
            ['hr', 'hr@example.test', 'hr', null],
            ['dina', 'dina.kartika@example.test', 'manager', 'emp_dina'],
            ['raka', 'raka.pratama@example.test', 'manager', 'emp_raka'],
            ['budi', 'budi.santoso@example.test', 'employee', 'emp_budi'],
            ['sari', 'sari.wulandari@example.test', 'employee', 'emp_sari'],
            ['nina', 'nina.lestari@example.test', 'employee', 'emp_nina'],
        ];
        foreach ($rows as [$key, $email, $role, $employee]) {
            $this->ids['user_' . $key] = $this->db->insert('users', [
                'email' => $email,
                'password_hash' => $hash,
                'role' => $role,
                'employee_id' => $employee === null ? null : $this->ids[$employee],
            ]);
        }
    }

    /**
     * A subset of Indonesian public holidays for 2026; real deployments load
     * the official SKB list each year.
     */
    private function holidays(): void
    {
        $holidays = [
            '2026-01-01' => 'Tahun Baru Masehi',
            '2026-02-17' => 'Tahun Baru Imlek',
            '2026-03-20' => 'Idul Fitri (hari pertama)',
            '2026-03-21' => 'Idul Fitri (hari kedua)',
            '2026-04-03' => 'Wafat Yesus Kristus',
            '2026-05-01' => 'Hari Buruh Internasional',
            '2026-06-01' => 'Hari Lahir Pancasila',
            '2026-08-17' => 'Hari Kemerdekaan Republik Indonesia',
            '2026-12-25' => 'Hari Raya Natal',
        ];
        foreach ($holidays as $date => $name) {
            $this->db->insert('holidays', ['holiday_date' => $date, 'name' => $name]);
        }
    }

    /**
     * Caps change every year; update them from the official BPJS announcement.
     * The JP cap rises each March, which the two JP rows show.
     */
    private function bpjs(): void
    {
        $settings = [
            ['kesehatan', 100, 400, 12_000_000],
            ['jht', 200, 370, null],
            ['jp', 100, 200, 10_042_300],
            ['jkk', 0, 24, null],
            ['jkm', 0, 30, null],
        ];
        foreach ($settings as [$program, $employee, $employer, $cap]) {
            $this->db->insert('bpjs_settings', [
                'program' => $program,
                'employee_rate_bp' => $employee,
                'employer_rate_bp' => $employer,
                'wage_cap' => $cap,
                'effective_from' => '2025-01-01',
            ]);
        }
        $this->db->insert('bpjs_settings', [
            'program' => 'jp',
            'employee_rate_bp' => 100,
            'employer_rate_bp' => 200,
            'wage_cap' => 10_547_400,
            'effective_from' => '2025-03-01',
        ]);
    }
}
