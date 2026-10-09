<?php

declare(strict_types=1);

namespace HrPayroll\Tests\Integration;

final class PayrollLifecycleTest extends PayrollTestCase
{
    public function testFullLifecycleDraftCalculatedApprovedPaid(): void
    {
        $this->recordBudisAugust();
        $periodId = $this->createPeriod('2026-08');

        $calculated = $this->calculate($periodId);
        $this->assertStatus(200, $calculated);
        $period = $this->json($calculated)['data'];
        self::assertSame('calculated', $period['status']);
        self::assertSame($this->ids['user_hr'], $period['calculated_by']);
        self::assertSame(6, $period['totals']['employees']);
        self::assertSame($period['totals']['net_pay'], $period['total_net_pay'], 'money is always an integer');

        $budi = $this->payslipFor($periodId, $this->ids['emp_budi']);
        self::assertSame(10_465_318, $budi['gross_pay']);
        self::assertSame(1_190_000, $budi['total_deductions']);
        self::assertSame(9_275_318, $budi['net_pay']);
        self::assertSame(870_400, $budi['employer_contributions']);
        $lines = array_column($budi['lines'], 'amount', 'code');
        self::assertSame(442_197, $lines['overtime_workday']);
        self::assertSame(1_523_121, $lines['overtime_rest_day']);
        self::assertSame(850_000, $lines['absence']);

        $approved = $this->approve($periodId, 'admin');
        $this->assertStatus(200, $approved);
        self::assertSame('approved', $this->json($approved)['data']['status']);

        $paid = $this->markPaid($periodId);
        $this->assertStatus(200, $paid);
        self::assertSame('paid', $this->json($paid)['data']['status']);

        $actions = array_column(
            $this->db->fetchAll("SELECT action FROM audit_logs WHERE entity_type = 'payroll_period' ORDER BY id"),
            'action',
        );
        self::assertSame(['payroll.created', 'payroll.calculated', 'payroll.approved', 'payroll.paid'], $actions);
    }

    public function testRecalculationIsAllowedUntilApprovalAndReplacesPayslips(): void
    {
        $periodId = $this->createPeriod('2026-08');
        $this->assertStatus(200, $this->calculate($periodId));
        $before = $this->payslipFor($periodId, $this->ids['emp_sari'])['net_pay'];

        $this->db->run('UPDATE employees SET base_salary = 6000000 WHERE id = ?', [$this->ids['emp_sari']]);
        $this->assertStatus(200, $this->calculate($periodId));

        self::assertNotSame($before, $this->payslipFor($periodId, $this->ids['emp_sari'])['net_pay']);
        self::assertSame(6, $this->countRows('SELECT COUNT(*) FROM payslips WHERE payroll_period_id = ?', [$periodId]));
        self::assertSame(1, $this->auditCount('payroll.recalculated'));
    }

    public function testCannotRecalculateAfterApproval(): void
    {
        $periodId = $this->createPeriod('2026-08');
        $this->calculate($periodId);
        $this->approve($periodId);

        $response = $this->calculate($periodId);
        $this->assertStatus(409, $response);
        self::assertSame('period_locked', $this->json($response)['code']);
    }

    public function testCalculatorCannotApproveTheirOwnCalculation(): void
    {
        $periodId = $this->createPeriod('2026-08', 'admin');
        $this->assertStatus(200, $this->calculate($periodId, 'admin'));

        $response = $this->approve($periodId, 'admin');
        $this->assertStatus(403, $response);
        self::assertSame('segregation_of_duties', $this->json($response)['code']);

        $this->assertStatus(200, $this->approve($periodId, 'admin2'));
    }

    public function testOnlyAdminsApproveAndMarkPaid(): void
    {
        $periodId = $this->createPeriod('2026-08');
        $this->calculate($periodId);

        $this->assertStatus(403, $this->approve($periodId, 'hr'));
        $this->assertStatus(403, $this->approve($periodId, 'dina'));
        $this->assertStatus(200, $this->approve($periodId, 'admin'));
        $this->assertStatus(403, $this->markPaid($periodId, 'hr'));
    }

    public function testTransitionsMustFollowTheOrder(): void
    {
        $periodId = $this->createPeriod('2026-08');

        $this->assertStatus(409, $this->approve($periodId));
        $this->assertStatus(409, $this->markPaid($periodId));
        $this->calculate($periodId);
        $this->assertStatus(409, $this->markPaid($periodId));
    }

    public function testDuplicatePeriodAndBadFormatAreRejected(): void
    {
        $this->createPeriod('2026-08');

        $this->assertStatus(409, $this->post('/api/v1/payroll-periods', ['period' => '2026-08'], 'hr'));
        $this->assertStatus(422, $this->post('/api/v1/payroll-periods', ['period' => '2026-8'], 'hr'));
    }

    public function testEmployeesHiredAfterThePeriodAreNotPaid(): void
    {
        $this->db->run("UPDATE employees SET hired_at = '2026-09-01' WHERE id = ?", [$this->ids['emp_yoga']]);
        $this->db->run("UPDATE employees SET status = 'inactive' WHERE id = ?", [$this->ids['emp_nina']]);
        $periodId = $this->createPeriod('2026-08');

        $this->calculate($periodId);

        self::assertSame(4, $this->countRows('SELECT COUNT(*) FROM payslips WHERE payroll_period_id = ?', [$periodId]));
    }

    public function testNegativeNetPayBlocksCalculationAndKeepsPreviousPayslips(): void
    {
        $periodId = $this->createPeriod('2026-08');
        $this->assertStatus(200, $this->calculate($periodId));
        $before = (int) $this->db->fetchInt('SELECT COUNT(*) FROM payslips WHERE payroll_period_id = ?', [$periodId]);

        // Absent on all 20 working days: the absence deduction equals the wage, so the
        // employee BPJS shares push net pay below zero.
        $august = new \DatePeriod(new \DateTimeImmutable('2026-08-01'), new \DateInterval('P1D'), 30);
        foreach ($august as $day) {
            if ((int) $day->format('N') <= 5 && $day->format('Y-m-d') !== '2026-08-17') {
                $this->db->insert('attendance_records', [
                    'employee_id' => $this->ids['emp_sari'],
                    'work_date' => $day->format('Y-m-d'),
                    'status' => 'absent',
                ]);
            }
        }

        $response = $this->calculate($periodId);
        $this->assertStatus(409, $response);
        $problem = $this->json($response);
        self::assertSame('negative_net_pay', $problem['code']);
        self::assertStringContainsString('EMP-003', $problem['detail']);
        self::assertSame('calculated', $this->periodStatus($periodId));
        self::assertSame(
            $before,
            $this->db->fetchInt('SELECT COUNT(*) FROM payslips WHERE payroll_period_id = ?', [$periodId]),
        );
        self::assertSame(0, $this->db->fetchInt('SELECT COUNT(*) FROM payslips WHERE net_pay < 0'));
    }

    public function testMissingBpjsSettingsBlockCalculation(): void
    {
        $this->db->run("DELETE FROM bpjs_settings WHERE program = 'jp'");
        $periodId = $this->createPeriod('2026-08');

        $response = $this->calculate($periodId);
        $this->assertStatus(409, $response);
        self::assertSame('bpjs_settings_missing', $this->json($response)['code']);
        self::assertSame('draft', $this->periodStatus($periodId));
    }

    public function testNewerBpjsSettingAppliesFromItsEffectiveMonth(): void
    {
        $response = $this->post('/api/v1/bpjs-settings', [
            'program' => 'jp',
            'employee_rate_bp' => 100,
            'employer_rate_bp' => 200,
            'wage_cap' => 11_000_000,
            'effective_from' => '2026-09-01',
        ], 'admin');
        $this->assertStatus(201, $response);

        $august = $this->createPeriod('2026-08');
        $september = $this->createPeriod('2026-09');
        $this->calculate($august);
        $this->calculate($september);

        $raka = static fn (array $p): int => array_column($p['lines'], 'amount', 'code')['bpjs_jp_employee'];
        self::assertSame(105_474, $raka($this->payslipFor($august, $this->ids['emp_raka'])));
        self::assertSame(110_000, $raka($this->payslipFor($september, $this->ids['emp_raka'])));
    }

    public function testPayslipVisibility(): void
    {
        $periodId = $this->createPeriod('2026-08');
        $this->calculate($periodId);
        $budiPayslip = $this->payslipFor($periodId, $this->ids['emp_budi'])['id'];

        $beforeApproval = $this->json($this->get('/api/v1/payslips', 'budi'))['data'];
        self::assertSame([], $beforeApproval, 'not published before approval');
        $this->assertStatus(404, $this->get("/api/v1/payslips/{$budiPayslip}", 'budi'));

        $this->approve($periodId);

        $own = $this->json($this->get('/api/v1/payslips', 'budi'))['data'];
        self::assertSame([$this->ids['emp_budi']], array_column($own, 'employee_id'));
        $this->assertStatus(200, $this->get("/api/v1/payslips/{$budiPayslip}", 'budi'));
        $this->assertStatus(404, $this->get("/api/v1/payslips/{$budiPayslip}", 'sari'));
        $asManager = $this->get("/api/v1/payslips/{$budiPayslip}", 'dina');
        $this->assertStatus(404, $asManager, 'managers do not see their reports pay');

        $this->assertStatus(403, $this->get("/api/v1/payroll-periods/{$periodId}/payslips", 'dina'));
        $path = "/api/v1/payroll-periods/{$periodId}/payslips?sort=-net_pay&per_page=2";
        $byPeriod = $this->json($this->get($path, 'hr'));
        self::assertSame(6, $byPeriod['meta']['total']);
        self::assertSame('Raka Pratama', $byPeriod['data'][0]['employee_name']);
    }
}
