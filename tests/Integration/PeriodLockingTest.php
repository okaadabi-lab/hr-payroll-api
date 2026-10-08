<?php

declare(strict_types=1);

namespace HrPayroll\Tests\Integration;

final class PeriodLockingTest extends PayrollTestCase
{
    private function approvedAugust(): int
    {
        $periodId = $this->createPeriod('2026-08');
        $this->assertStatus(200, $this->calculate($periodId));
        $this->assertStatus(200, $this->approve($periodId));

        return $periodId;
    }

    public function testAttendanceInAnApprovedPeriodIsLocked(): void
    {
        $recordId = $this->db->insert('attendance_records', [
            'employee_id' => $this->ids['emp_budi'],
            'work_date' => '2026-08-03',
            'status' => 'present',
        ]);
        $this->approvedAugust();

        $create = $this->post('/api/v1/attendance', [
            'employee_id' => $this->ids['emp_sari'],
            'work_date' => '2026-08-04',
            'status' => 'absent',
        ], 'hr');
        $this->assertStatus(409, $create);
        self::assertSame('period_locked', $this->json($create)['code']);

        $this->assertStatus(409, $this->request('PUT', "/api/v1/attendance/{$recordId}", ['status' => 'absent'], 'hr'));
        $this->assertStatus(409, $this->request('DELETE', "/api/v1/attendance/{$recordId}", null, 'hr'));
    }

    public function testOvertimeInAnApprovedPeriodIsLocked(): void
    {
        $pendingId = $this->db->insert('overtime_requests', [
            'employee_id' => $this->ids['emp_budi'],
            'work_date' => '2026-08-04',
            'minutes' => 60,
            'reason' => 'Late delivery',
        ]);
        $this->approvedAugust();

        $this->assertStatus(409, $this->post('/api/v1/overtime-requests', [
            'work_date' => '2026-08-05',
            'hours' => 1,
            'reason' => 'Late delivery',
        ], 'budi'));
        $this->assertStatus(409, $this->post("/api/v1/overtime-requests/{$pendingId}/approve", [], 'dina'));
    }

    public function testHolidaysInAnApprovedPeriodAreLocked(): void
    {
        $this->approvedAugust();

        $holiday = ['holiday_date' => '2026-08-18', 'name' => 'Cuti bersama'];
        $this->assertStatus(409, $this->post('/api/v1/holidays', $holiday, 'hr'));
    }

    public function testOtherPeriodsStayWritable(): void
    {
        $this->approvedAugust();

        $this->assertStatus(201, $this->post('/api/v1/attendance', [
            'employee_id' => $this->ids['emp_budi'],
            'work_date' => '2026-09-01',
            'status' => 'present',
            'clock_in' => '08:00',
            'clock_out' => '17:00',
        ], 'hr'));
    }

    public function testBpjsSettingThatWouldChangeAnApprovedPeriodIsRejected(): void
    {
        $this->approvedAugust();

        $response = $this->post('/api/v1/bpjs-settings', [
            'program' => 'jp',
            'employee_rate_bp' => 100,
            'employer_rate_bp' => 200,
            'wage_cap' => 11_000_000,
            'effective_from' => '2026-08-01',
        ], 'admin');
        $this->assertStatus(409, $response);
    }

    public function testChangingInputsOfACalculatedPeriodSendsItBackToDraft(): void
    {
        $periodId = $this->createPeriod('2026-08');
        $this->calculate($periodId);

        $this->assertStatus(201, $this->post('/api/v1/attendance', [
            'employee_id' => $this->ids['emp_budi'],
            'work_date' => '2026-08-04',
            'status' => 'absent',
        ], 'hr'));

        self::assertSame('draft', $this->periodStatus($periodId));
        $this->assertStatus(409, $this->approve($periodId), 'stale figures cannot be approved');
        self::assertSame(1, $this->auditCount('payroll.invalidated'));
    }

    public function testSalaryChangeSendsCalculatedPeriodsBackToDraft(): void
    {
        $periodId = $this->createPeriod('2026-08');
        $this->calculate($periodId);

        $this->request('PATCH', "/api/v1/employees/{$this->ids['emp_budi']}", ['base_salary' => 8_000_000], 'hr');

        self::assertSame('draft', $this->periodStatus($periodId));
    }
}
