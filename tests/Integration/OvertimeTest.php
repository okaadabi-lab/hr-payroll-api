<?php

declare(strict_types=1);

namespace HrPayroll\Tests\Integration;

use Psr\Http\Message\ResponseInterface;

final class OvertimeTest extends IntegrationTestCase
{
    private function submit(string $as, string $date, float $hours, ?int $employeeId = null): ResponseInterface
    {
        $body = ['work_date' => $date, 'hours' => $hours, 'reason' => 'Month-end stock count'];
        if ($employeeId !== null) {
            $body['employee_id'] = $employeeId;
        }

        return $this->post('/api/v1/overtime-requests', $body, $as);
    }

    private function submitted(string $as, string $date, float $hours): int
    {
        $response = $this->submit($as, $date, $hours);
        $this->assertStatus(201, $response);

        return (int) $this->json($response)['data']['id'];
    }

    public function testEmployeeSubmitsOvertimeForThemself(): void
    {
        $response = $this->submit('budi', '2026-09-07', 2.5);

        $this->assertStatus(201, $response);
        $data = $this->json($response)['data'];
        self::assertSame($this->ids['emp_budi'], $data['employee_id']);
        self::assertSame(150, $data['minutes']);
        self::assertSame(2.5, $data['hours']);
        self::assertSame('pending', $data['status']);
    }

    public function testMoreThanFourHoursOnAWorkdayIsRejected(): void
    {
        $response = $this->submit('budi', '2026-09-07', 4.5);

        $this->assertStatus(422, $response);
        self::assertStringContainsString('4 hours per day', $this->json($response)['errors'][0]['message']);
    }

    public function testDailyLimitCountsEarlierRequestsOnTheSameDay(): void
    {
        $this->submitted('budi', '2026-09-07', 3);

        $this->assertStatus(422, $this->submit('budi', '2026-09-07', 1.5));
        $this->assertStatus(201, $this->submit('budi', '2026-09-07', 1));
    }

    public function testWeeklyLimitIsEighteenHours(): void
    {
        foreach (['2026-09-07', '2026-09-08', '2026-09-09', '2026-09-10'] as $date) {
            $this->submitted('budi', $date, 4);
        }

        $response = $this->submit('budi', '2026-09-11', 2.5);
        $this->assertStatus(422, $response);
        self::assertStringContainsString('18 hours per week', $this->json($response)['errors'][0]['message']);

        $this->assertStatus(201, $this->submit('budi', '2026-09-11', 2));
        $this->assertStatus(201, $this->submit('budi', '2026-09-14', 4), 'next ISO week starts fresh');
    }

    public function testRejectedRequestsDoNotCountTowardsTheLimit(): void
    {
        $id = $this->submitted('budi', '2026-09-07', 4);
        $rejected = $this->post("/api/v1/overtime-requests/{$id}/reject", ['note' => 'Not needed'], 'dina');
        $this->assertStatus(200, $rejected);

        $this->assertStatus(201, $this->submit('budi', '2026-09-07', 4));
    }

    public function testRestDayOvertimeAllowsUpToTwelveHoursOutsideTheWeeklyCap(): void
    {
        foreach (['2026-09-07', '2026-09-08', '2026-09-09', '2026-09-10'] as $date) {
            $this->submitted('budi', $date, 4);
        }

        $this->assertStatus(201, $this->submit('budi', '2026-09-12', 10));
        $this->assertStatus(422, $this->submit('budi', '2026-09-12', 2.5));
    }

    public function testHoursMustBeInHalfHourSteps(): void
    {
        $this->assertStatus(422, $this->submit('budi', '2026-09-07', 1.25));
        $this->assertStatus(422, $this->submit('budi', '2026-09-07', 0));
    }

    public function testAbsurdHoursAreRejectedBeforeConversionToMinutes(): void
    {
        foreach ([24.5, 1e20, -3] as $hours) {
            $response = $this->submit('budi', '2026-09-12', $hours);
            $this->assertStatus(422, $response);
            self::assertSame('The request contains invalid data.', $this->json($response)['detail']);
        }
        self::assertSame(0, $this->db->fetchInt('SELECT COUNT(*) FROM overtime_requests'));
    }

    public function testDirectManagerApproves(): void
    {
        $id = $this->submitted('budi', '2026-09-07', 2);

        $response = $this->post("/api/v1/overtime-requests/{$id}/approve", ['note' => 'OK'], 'dina');

        $this->assertStatus(200, $response);
        $data = $this->json($response)['data'];
        self::assertSame('approved', $data['status']);
        self::assertSame($this->ids['user_dina'], $data['decided_by']);
        self::assertSame(1, $this->auditCount('overtime.approved'));
    }

    public function testManagerCannotApproveTheirOwnRequest(): void
    {
        $id = $this->submitted('dina', '2026-09-07', 2);

        $response = $this->post("/api/v1/overtime-requests/{$id}/approve", [], 'dina');

        $this->assertStatus(403, $response);
        self::assertSame('self_approval', $this->json($response)['code']);
    }

    public function testManagerCannotApproveAnotherTeamsRequest(): void
    {
        $id = $this->submitted('nina', '2026-09-07', 2);

        $response = $this->post("/api/v1/overtime-requests/{$id}/approve", [], 'dina');

        $this->assertStatus(403, $response);
        self::assertSame('out_of_scope', $this->json($response)['code']);
        $stored = $this->db->fetchOne('SELECT status FROM overtime_requests WHERE id = ?', [$id]);
        self::assertSame('pending', $stored['status'] ?? null);
    }

    public function testEmployeesAndHrCannotApprove(): void
    {
        $id = $this->submitted('budi', '2026-09-07', 2);

        $this->assertStatus(403, $this->post("/api/v1/overtime-requests/{$id}/approve", [], 'sari'));
        $this->assertStatus(403, $this->post("/api/v1/overtime-requests/{$id}/approve", [], 'hr'));
    }

    public function testAdminCanDecideWhenTheManagerIsUnavailable(): void
    {
        $id = $this->submitted('dina', '2026-09-07', 2);

        $this->assertStatus(200, $this->post("/api/v1/overtime-requests/{$id}/approve", [], 'admin'));
    }

    public function testDecidedRequestCannotBeDecidedAgain(): void
    {
        $id = $this->submitted('budi', '2026-09-07', 2);
        $this->post("/api/v1/overtime-requests/{$id}/approve", [], 'dina');

        $response = $this->post("/api/v1/overtime-requests/{$id}/reject", [], 'dina');
        $this->assertStatus(409, $response);
        self::assertSame('already_decided', $this->json($response)['code']);
    }

    public function testEmployeeCannotSubmitForSomeoneElse(): void
    {
        $this->assertStatus(403, $this->submit('budi', '2026-09-07', 2, $this->ids['emp_sari']));
        $this->assertStatus(201, $this->submit('hr', '2026-09-07', 2, $this->ids['emp_sari']));
    }

    public function testEmployeeCannotSeeAColleaguesRequest(): void
    {
        $id = $this->submitted('budi', '2026-09-07', 2);

        $this->assertStatus(403, $this->get("/api/v1/overtime-requests/{$id}", 'sari'));
        $this->assertStatus(200, $this->get("/api/v1/overtime-requests/{$id}", 'dina'));
    }

    public function testListFiltersByStatusAndPeriod(): void
    {
        $this->submitted('budi', '2026-09-07', 2);
        $this->submitted('budi', '2026-10-05', 2);

        $september = $this->json($this->get('/api/v1/overtime-requests?period=2026-09&status=pending', 'hr'));
        self::assertSame(['2026-09-07'], array_column($september['data'], 'work_date'));
        $this->assertStatus(422, $this->get('/api/v1/overtime-requests?period=2026-13', 'hr'));
    }
}
