<?php

declare(strict_types=1);

namespace HrPayroll\Tests\Integration;

use HrPayroll\Infrastructure\Database;
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

    public function testConcurrentDuplicatesRunTheCalculationOnce(): void
    {
        $periodId = $this->createPeriod('2026-08');
        $token = $this->tokenFor('hr');

        // A separate connection holds the period row, so the first request stops right
        // after reserving its key and the duplicate has to wait on that key.
        $blocker = Database::fromConfig($this->container->config());
        $blocker->pdo()->beginTransaction();
        $blocker->fetchOne('SELECT id FROM payroll_periods WHERE id = ? FOR UPDATE', [$periodId]);

        $processes = [];
        foreach ([1, 2] as $ignored) {
            $processes[] = $this->startCalculation($periodId, $token, 'concurrent-key-01');
            usleep(300_000);
        }
        $blocker->pdo()->commit();

        $results = array_map($this->finish(...), $processes);
        sort($results);
        self::assertSame(['200 false', '200 true'], $results);
        self::assertSame(1, $this->auditCount('payroll.calculated'));
        self::assertSame(0, $this->auditCount('payroll.recalculated'));
    }

    /**
     * @return array{0: resource, 1: resource}
     */
    private function startCalculation(int $periodId, string $token, string $key): array
    {
        $process = proc_open(
            [
                PHP_BINARY,
                dirname(__DIR__) . '/Support/calculate_request.php',
                self::NOW,
                "/api/v1/payroll-periods/{$periodId}/calculate",
                $token,
                $key,
            ],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        self::assertIsResource($process);

        return [$process, $pipes[1]];
    }

    /**
     * @param array{0: resource, 1: resource} $started
     */
    private function finish(array $started): string
    {
        $output = trim((string) stream_get_contents($started[1]));
        proc_close($started[0]);

        return $output;
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
