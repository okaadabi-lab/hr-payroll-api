<?php

declare(strict_types=1);

namespace HrPayroll\Tests\Integration;

use HrPayroll\Service\IdempotencyService;

final class IdempotencyTest extends PayrollTestCase
{
    public function testReplayReturnsTheOriginalResponseWithoutRecalculating(): void
    {
        $periodId = $this->createPeriod('2026-08');
        $first = $this->calculate($periodId, 'hr', 'calc-2026-08-a', ['note' => 'first run']);
        $this->assertStatus(200, $first);
        self::assertSame('false', $first->getHeaderLine('Idempotent-Replayed'));

        // A change that would alter the result if the calculation ran again.
        $this->db->run('UPDATE employees SET base_salary = 99000000 WHERE id = ?', [$this->ids['emp_sari']]);

        $replay = $this->calculate($periodId, 'hr', 'calc-2026-08-a', ['note' => 'first run']);
        $this->assertStatus(200, $replay);
        self::assertSame('true', $replay->getHeaderLine('Idempotent-Replayed'));
        self::assertSame($this->json($first), $this->json($replay));
        self::assertSame(1, $this->auditCount('payroll.calculated'));
        self::assertSame(0, $this->auditCount('payroll.recalculated'));
    }

    public function testSameKeyWithDifferentPayloadIs422(): void
    {
        $periodId = $this->createPeriod('2026-08');
        $this->assertStatus(200, $this->calculate($periodId, 'hr', 'calc-2026-08-b', ['note' => 'first run']));

        $response = $this->calculate($periodId, 'hr', 'calc-2026-08-b', ['note' => 'different']);
        $this->assertStatus(422, $response);
        self::assertSame('Idempotency-Key', $this->json($response)['errors'][0]['field']);
    }

    public function testSameKeyOnAnotherPeriodIs422(): void
    {
        $august = $this->createPeriod('2026-08');
        $september = $this->createPeriod('2026-09');
        $this->calculate($august, 'hr', 'shared-key-123');

        $this->assertStatus(422, $this->calculate($september, 'hr', 'shared-key-123'));
    }

    public function testKeyOrderInTheBodyDoesNotMatter(): void
    {
        self::assertSame(
            IdempotencyService::requestHash('POST', '/x', ['a' => 1, 'b' => ['d' => 2, 'c' => 3]]),
            IdempotencyService::requestHash('POST', '/x', ['b' => ['c' => 3, 'd' => 2], 'a' => 1]),
        );
    }

    public function testKeysAreScopedPerUser(): void
    {
        $periodId = $this->createPeriod('2026-08');
        $this->assertStatus(200, $this->calculate($periodId, 'hr', 'my-shared-key'));

        $other = $this->calculate($periodId, 'admin', 'my-shared-key');
        $this->assertStatus(200, $other);
        self::assertSame('false', $other->getHeaderLine('Idempotent-Replayed'));
    }

    public function testFailedRequestDoesNotConsumeTheKey(): void
    {
        $this->db->run("DELETE FROM bpjs_settings WHERE program = 'jkm'");
        $periodId = $this->createPeriod('2026-08');
        $this->assertStatus(409, $this->calculate($periodId, 'hr', 'retry-after-fix'));

        $this->db->insert('bpjs_settings', [
            'program' => 'jkm',
            'employee_rate_bp' => 0,
            'employer_rate_bp' => 30,
            'wage_cap' => null,
            'effective_from' => '2025-01-01',
        ]);

        $retry = $this->calculate($periodId, 'hr', 'retry-after-fix');
        $this->assertStatus(200, $retry);
        self::assertSame('false', $retry->getHeaderLine('Idempotent-Replayed'));
    }

    public function testKeyIsRequired(): void
    {
        $periodId = $this->createPeriod('2026-08');

        $response = $this->post("/api/v1/payroll-periods/{$periodId}/calculate", [], 'hr');
        $this->assertStatus(400, $response);
        self::assertSame('idempotency_key_required', $this->json($response)['code']);
    }
}
