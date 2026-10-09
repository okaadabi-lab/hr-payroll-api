<?php

declare(strict_types=1);

namespace HrPayroll\Tests\Integration;

/**
 * Seeded reporting lines: Dina manages Budi and Sari (Operations),
 * Raka manages Nina (Engineering). Yoga has no account.
 */
final class AccessControlTest extends IntegrationTestCase
{
    /**
     * @return list<string>
     */
    private function visibleEmployeeNames(string $as): array
    {
        $response = $this->request('GET', '/api/v1/employees?per_page=100', null, $as);
        $this->assertStatus(200, $response);

        return array_column($this->json($response)['data'], 'full_name');
    }

    public function testHrSeesEveryEmployee(): void
    {
        self::assertCount(6, $this->visibleEmployeeNames('hr'));
    }

    public function testManagerSeesThemselfAndDirectReportsOnly(): void
    {
        self::assertSame(['Budi Santoso', 'Dina Kartika', 'Sari Wulandari'], $this->visibleEmployeeNames('dina'));
        self::assertSame(['Nina Lestari', 'Raka Pratama'], $this->visibleEmployeeNames('raka'));
    }

    public function testEmployeeSeesOnlyThemself(): void
    {
        self::assertSame(['Budi Santoso'], $this->visibleEmployeeNames('budi'));
    }

    public function testManagerCannotOpenAnotherTeamsEmployee(): void
    {
        $this->assertStatus(200, $this->get("/api/v1/employees/{$this->ids['emp_budi']}", 'dina'));

        $response = $this->get("/api/v1/employees/{$this->ids['emp_nina']}", 'dina');
        $this->assertStatus(403, $response);
        self::assertSame('out_of_scope', $this->json($response)['code']);
    }

    public function testEmployeeCannotOpenAColleague(): void
    {
        $this->assertStatus(403, $this->get("/api/v1/employees/{$this->ids['emp_sari']}", 'budi'));
        $this->assertStatus(403, $this->get("/api/v1/employees/{$this->ids['emp_dina']}", 'budi'));
    }

    public function testManagerDoesNotSeeReportsSalaries(): void
    {
        $report = $this->json($this->get("/api/v1/employees/{$this->ids['emp_budi']}", 'dina'))['data'];
        $self = $this->json($this->get("/api/v1/employees/{$this->ids['emp_dina']}", 'dina'))['data'];

        self::assertArrayNotHasKey('base_salary', $report);
        self::assertSame(15_000_000, $self['base_salary']);
    }

    public function testManagerCannotRankReportsBySalary(): void
    {
        foreach (['base_salary', '-base_salary', 'name,base_salary'] as $sort) {
            $response = $this->get("/api/v1/employees?sort={$sort}", 'dina');
            $this->assertStatus(422, $response);
            self::assertSame('sort', $this->json($response)['errors'][0]['field']);
        }
        $this->assertStatus(200, $this->get('/api/v1/employees?sort=-base_salary', 'hr'));
    }

    public function testScopeAlsoAppliesToAttendanceAndOvertimeLists(): void
    {
        foreach (['emp_budi', 'emp_nina'] as $employee) {
            $this->db->insert('attendance_records', [
                'employee_id' => $this->ids[$employee],
                'work_date' => '2026-09-01',
                'status' => 'present',
            ]);
            $this->db->insert('overtime_requests', [
                'employee_id' => $this->ids[$employee],
                'work_date' => '2026-09-01',
                'minutes' => 60,
                'reason' => 'Stock count',
            ]);
        }

        foreach (['/api/v1/attendance', '/api/v1/overtime-requests'] as $path) {
            $names = array_column($this->json($this->get($path, 'dina'))['data'], 'employee_name');
            self::assertSame(['Budi Santoso'], $names, $path);
            self::assertSame([], $this->json($this->get($path, 'sari'))['data'], $path);
            self::assertCount(2, $this->json($this->get($path, 'hr'))['data'], $path);
        }
    }

    public function testEmployeeFilterCannotWidenTheScope(): void
    {
        $response = $this->get("/api/v1/employees?manager_id={$this->ids['emp_raka']}", 'dina');

        self::assertSame([], $this->json($response)['data']);
    }

    public function testOnlyHrAndAdminCanWriteMasterData(): void
    {
        $department = ['code' => 'MKT', 'name' => 'Marketing'];

        $this->assertStatus(403, $this->post('/api/v1/departments', $department, 'dina'));
        $this->assertStatus(403, $this->post('/api/v1/departments', $department, 'budi'));
        $this->assertStatus(201, $this->post('/api/v1/departments', $department, 'hr'));
        $path = "/api/v1/employees/{$this->ids['emp_budi']}";
        $salaryChange = $this->request('PATCH', $path, ['base_salary' => 1], 'dina');
        $this->assertStatus(403, $salaryChange);
    }

    public function testOnlyAdminCanCreateUsersAndReadTheAuditLog(): void
    {
        $user = [
            'email' => 'yoga@example.test',
            'password' => 'a-long-password-1',
            'role' => 'employee',
            'employee_id' => $this->ids['emp_yoga'],
        ];

        $this->assertStatus(403, $this->post('/api/v1/users', $user, 'hr'));
        $this->assertStatus(201, $this->post('/api/v1/users', $user, 'admin'));
        $this->assertStatus(409, $this->post('/api/v1/users', $user, 'admin'));
        $this->assertStatus(403, $this->get('/api/v1/audit-logs', 'hr'));
        $this->assertStatus(200, $this->get('/api/v1/audit-logs', 'admin'));
    }

    public function testRoutesRequireAToken(): void
    {
        foreach (['/api/v1/me', '/api/v1/employees', '/api/v1/payslips', '/api/v1/payroll-periods'] as $path) {
            $response = $this->request('GET', $path);
            $this->assertStatus(401, $response);
            self::assertSame('Bearer', $response->getHeaderLine('WWW-Authenticate'));
        }
    }
}
