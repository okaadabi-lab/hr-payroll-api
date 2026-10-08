<?php

declare(strict_types=1);

namespace HrPayroll\Tests\Integration;

final class EmployeeTest extends IntegrationTestCase
{
    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function newEmployee(array $overrides = []): array
    {
        return array_merge([
            'employee_number' => 'EMP-100',
            'full_name' => 'Putri Maharani',
            'email' => 'putri.maharani@example.test',
            'phone' => '+628120000100',
            'position' => 'Analyst',
            'department_id' => $this->ids['d_fin'],
            'manager_id' => $this->ids['emp_yoga'],
            'base_salary' => 8_000_000,
            'fixed_allowances' => 500_000,
            'hired_at' => '2026-09-01',
        ], $overrides);
    }

    public function testHrCreatesAnEmployee(): void
    {
        $response = $this->post('/api/v1/employees', $this->newEmployee(), 'hr');

        $this->assertStatus(201, $response);
        $employee = $this->json($response)['data'];
        self::assertSame('Finance', $employee['department_name']);
        self::assertSame('Yoga Firmansyah', $employee['manager_name']);
        self::assertSame(8_000_000, $employee['base_salary']);
    }

    public function testDuplicateEmployeeNumberIsAConflict(): void
    {
        $response = $this->post('/api/v1/employees', $this->newEmployee(['employee_number' => 'EMP-001']), 'hr');

        $this->assertStatus(409, $response);
    }

    public function testMoneyMustBeAnIntegerNumberOfRupiah(): void
    {
        $response = $this->post('/api/v1/employees', $this->newEmployee(['base_salary' => 8_000_000.5]), 'hr');

        $this->assertStatus(422, $response);
        self::assertSame('base_salary', $this->json($response)['errors'][0]['field']);
    }

    public function testUnknownDepartmentIsAValidationError(): void
    {
        $response = $this->post('/api/v1/employees', $this->newEmployee(['department_id' => 999]), 'hr');

        $this->assertStatus(422, $response);
    }

    public function testSalaryChangeIsAuditedWithBeforeAndAfter(): void
    {
        $id = $this->ids['emp_budi'];
        $changes = ['base_salary' => 8_000_000, 'position' => 'Senior Supervisor'];
        $response = $this->request('PATCH', "/api/v1/employees/{$id}", $changes, 'hr');
        $this->assertStatus(200, $response);

        $log = $this->db->fetchOne(
            "SELECT actor_user_id, before_data, after_data, ip FROM audit_logs
             WHERE action = 'employee.salary_changed' AND entity_id = ?",
            [$id],
        );
        self::assertNotNull($log);
        self::assertSame($this->ids['user_hr'], (int) $log['actor_user_id']);
        self::assertSame(['base_salary' => 7_500_000], json_decode((string) $log['before_data'], true));
        self::assertSame(['base_salary' => 8_000_000], json_decode((string) $log['after_data'], true));
        self::assertSame('203.0.113.7', $log['ip']);
        self::assertSame(1, $this->auditCount('employee.updated'));
    }

    public function testUnchangedSalaryIsNotAudited(): void
    {
        $this->request('PATCH', "/api/v1/employees/{$this->ids['emp_budi']}", ['base_salary' => 7_500_000], 'hr');

        self::assertSame(0, $this->auditCount('employee.salary_changed'));
    }

    public function testManagerCycleIsRejected(): void
    {
        $change = ['manager_id' => $this->ids['emp_budi']];
        $response = $this->request('PATCH', "/api/v1/employees/{$this->ids['emp_dina']}", $change, 'hr');

        $this->assertStatus(422, $response);
        self::assertSame('manager_id', $this->json($response)['errors'][0]['field']);
    }

    public function testListPaginationFilteringSortingAndSearch(): void
    {
        $page = $this->json($this->get('/api/v1/employees?per_page=2&page=2&sort=-base_salary', 'hr'));

        self::assertSame(['Nina Lestari', 'Yoga Firmansyah'], array_column($page['data'], 'full_name'));
        self::assertSame(['page' => 2, 'per_page' => 2, 'total' => 6, 'total_pages' => 3], $page['meta']);
        self::assertStringContainsString('page=3', (string) $page['links']['next']);
        self::assertStringContainsString('page=1', (string) $page['links']['prev']);

        $filtered = $this->json($this->get("/api/v1/employees?department_id={$this->ids['d_eng']}", 'hr'));
        self::assertSame(['Nina Lestari', 'Raka Pratama'], array_column($filtered['data'], 'full_name'));

        $search = $this->json($this->get('/api/v1/employees?search=sant', 'hr'));
        self::assertSame(['Budi Santoso'], array_column($search['data'], 'full_name'));
    }

    public function testSortingIsWhitelisted(): void
    {
        $response = $this->get('/api/v1/employees?sort=password_hash', 'hr');

        $this->assertStatus(422, $response);
        self::assertSame('sort', $this->json($response)['errors'][0]['field']);
    }

    public function testPerPageIsCapped(): void
    {
        $this->assertStatus(422, $this->get('/api/v1/employees?per_page=1000', 'hr'));
    }

    public function testSearchTreatsWildcardsLiterally(): void
    {
        $response = $this->json($this->get('/api/v1/employees?search=%25', 'hr'));

        self::assertSame([], $response['data']);
    }
}
