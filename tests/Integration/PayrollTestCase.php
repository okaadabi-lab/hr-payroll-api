<?php

declare(strict_types=1);

namespace HrPayroll\Tests\Integration;

use Psr\Http\Message\ResponseInterface;

abstract class PayrollTestCase extends IntegrationTestCase
{
    protected function createPeriod(string $period = '2026-08', string $as = 'hr'): int
    {
        $response = $this->post('/api/v1/payroll-periods', ['period' => $period], $as);
        $this->assertStatus(201, $response);

        return (int) $this->json($response)['data']['id'];
    }

    /**
     * @param array<string, mixed> $body
     */
    protected function calculate(
        int $periodId,
        string $as = 'hr',
        ?string $key = null,
        array $body = [],
    ): ResponseInterface {
        return $this->request(
            'POST',
            "/api/v1/payroll-periods/{$periodId}/calculate",
            $body,
            $as,
            ['Idempotency-Key' => $key ?? bin2hex(random_bytes(8))],
        );
    }

    protected function approve(int $periodId, string $as = 'admin'): ResponseInterface
    {
        return $this->post("/api/v1/payroll-periods/{$periodId}/approve", null, $as);
    }

    protected function markPaid(int $periodId, string $as = 'admin'): ResponseInterface
    {
        return $this->post("/api/v1/payroll-periods/{$periodId}/mark-paid", null, $as);
    }

    /**
     * Budi's August 2026 inputs, identical to the worked example in
     * PayrollCalculatorTest::testWorkedExampleAugust2026 and docs/payroll-rules.md.
     */
    protected function recordBudisAugust(): void
    {
        foreach (['2026-08-20', '2026-08-21'] as $date) {
            $this->assertStatus(201, $this->post('/api/v1/attendance', [
                'employee_id' => $this->ids['emp_budi'],
                'work_date' => $date,
                'status' => 'absent',
            ], 'hr'));
        }
        foreach ([['2026-08-04', 3], ['2026-08-05', 2], ['2026-08-08', 10], ['2026-08-17', 4]] as [$date, $hours]) {
            $submitted = $this->post('/api/v1/overtime-requests', [
                'work_date' => $date,
                'hours' => $hours,
                'reason' => 'Warehouse migration',
            ], 'budi');
            $this->assertStatus(201, $submitted);
            $id = $this->json($submitted)['data']['id'];
            $this->assertStatus(200, $this->post("/api/v1/overtime-requests/{$id}/approve", [], 'dina'));
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function payslipFor(int $periodId, int $employeeId): array
    {
        $row = $this->db->fetchOne(
            'SELECT id FROM payslips WHERE payroll_period_id = ? AND employee_id = ?',
            [$periodId, $employeeId],
        );
        self::assertNotNull($row, 'Payslip not found');

        return $this->json($this->get("/api/v1/payslips/{$row['id']}", 'hr'))['data'];
    }
}
