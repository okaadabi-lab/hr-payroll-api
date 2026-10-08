<?php

declare(strict_types=1);

namespace HrPayroll\Tests\Integration;

use HrPayroll\Infrastructure\Seeder;
use HrPayroll\Service\PayrollService;
use Psr\Http\Message\ResponseInterface;

final class OutboxTest extends PayrollTestCase
{
    private function outbox(string $query = '', ?string $key = null): ResponseInterface
    {
        $headers = ['X-Api-Key' => $key ?? $this->apiKey];

        return $this->request('GET', '/api/v1/integrations/outbox' . $query, null, null, $headers);
    }

    /**
     * @param list<int> $ids
     */
    private function ack(array $ids): ResponseInterface
    {
        return $this->post('/api/v1/integrations/outbox/ack', ['ids' => $ids], null, ['X-Api-Key' => $this->apiKey]);
    }

    public function testApprovalPublishesOnePayslipEventPerEmployee(): void
    {
        $periodId = $this->createPeriod('2026-08');
        $this->calculate($periodId);
        self::assertSame(0, $this->countRows('SELECT COUNT(*) FROM outbox_events'), 'nothing before approval');

        $this->approve($periodId);

        $response = $this->outbox('?limit=10');
        $this->assertStatus(200, $response);
        $body = $this->json($response);
        self::assertCount(6, $body['data']);
        self::assertSame(6, $body['meta']['pending_total']);

        $event = $body['data'][0];
        self::assertSame(['id', 'type', 'occurred_at', 'payload'], array_keys($event));
        self::assertSame(PayrollService::EVENT_PAYSLIP_PUBLISHED, $event['type']);
        self::assertSame(
            ['employee_id', 'employee_name', 'phone', 'period', 'net_pay', 'payslip_url'],
            array_keys($event['payload']),
        );
        $budi = array_values(array_filter(
            $body['data'],
            fn (array $event): bool => $event['payload']['employee_id'] === $this->ids['emp_budi'],
        ))[0];
        self::assertSame('Budi Santoso', $budi['payload']['employee_name']);
        self::assertSame('+62812000002', $budi['payload']['phone']);
        self::assertSame('2026-08', $budi['payload']['period']);
        self::assertSame(
            $this->payslipFor($periodId, $this->ids['emp_budi'])['net_pay'],
            $budi['payload']['net_pay'],
        );
        $url = $budi['payload']['payslip_url'];
        self::assertMatchesRegularExpression('#^http://localhost:8082/api/v1/payslips/\d+$#', $url);
    }

    public function testMarkingPaidPublishesPaidEvents(): void
    {
        $periodId = $this->createPeriod('2026-08');
        $this->calculate($periodId);
        $this->approve($periodId);
        $this->markPaid($periodId);

        $types = array_count_values(array_column($this->json($this->outbox('?limit=100'))['data'], 'type'));
        self::assertSame(6, $types[PayrollService::EVENT_PAYSLIP_PUBLISHED]);
        self::assertSame(6, $types[PayrollService::EVENT_PAYROLL_PAID]);
    }

    public function testEventsAreWrittenInTheSameTransactionAsTheApproval(): void
    {
        $periodId = $this->createPeriod('2026-08');
        $this->calculate($periodId);

        // Make the outbox insert fail half-way through the approval.
        $this->db->pdo()->exec('ALTER TABLE outbox_events ADD CONSTRAINT chk_test_fail CHECK (aggregate_id < 0)');
        try {
            $this->assertStatus(500, $this->approve($periodId));
        } finally {
            $this->db->pdo()->exec('ALTER TABLE outbox_events DROP CONSTRAINT chk_test_fail');
        }

        self::assertSame('calculated', $this->periodStatus($periodId));
        self::assertSame(0, $this->auditCount('payroll.approved'));
        self::assertSame(0, $this->countRows('SELECT COUNT(*) FROM outbox_events'));
    }

    public function testCursorPaginationAndAck(): void
    {
        $periodId = $this->createPeriod('2026-08');
        $this->calculate($periodId);
        $this->approve($periodId);

        $first = $this->json($this->outbox('?limit=4'));
        self::assertCount(4, $first['data']);
        $second = $this->json($this->outbox("?after={$first['meta']['next_after']}&limit=4"));
        self::assertCount(2, $second['data']);

        $firstIds = array_column($first['data'], 'id');
        $ack = $this->ack([...$firstIds, 999_999]);
        $this->assertStatus(200, $ack);
        self::assertSame(
            ['acked' => $firstIds, 'already_acked' => [], 'unknown' => [999_999]],
            $this->json($ack)['data'],
        );

        $remaining = $this->json($this->outbox());
        self::assertSame(array_column($second['data'], 'id'), array_column($remaining['data'], 'id'));
        self::assertSame(2, $remaining['meta']['pending_total']);

        $again = $this->json($this->ack([$firstIds[0]]))['data'];
        self::assertSame([$firstIds[0]], $again['already_acked'], 'acking twice is harmless');
    }

    public function testOutboxRequiresAValidKeyWithTheRightScope(): void
    {
        $this->assertStatus(401, $this->request('GET', '/api/v1/integrations/outbox'));
        $this->assertStatus(401, $this->outbox('', 'hrp_000000000000_' . str_repeat('0', 48)));
        $this->assertStatus(401, $this->outbox('', substr($this->apiKey, 0, -1) . 'f'));
        $this->assertStatus(401, $this->get('/api/v1/integrations/outbox', 'admin'), 'JWTs are not accepted');

        $seeder = new Seeder($this->db);
        $wrongScope = $seeder->createApiClient('reporting', 'reports:read');
        $response = $this->outbox('', $wrongScope);
        $this->assertStatus(403, $response);
        self::assertSame('insufficient_scope', $this->json($response)['code']);
    }

    public function testAckValidatesIds(): void
    {
        $this->assertStatus(422, $this->ack([]));
        $headers = ['X-Api-Key' => $this->apiKey];
        $stringIds = $this->post('/api/v1/integrations/outbox/ack', ['ids' => ['1']], null, $headers);
        $this->assertStatus(422, $stringIds);
        $this->assertStatus(422, $this->outbox('?limit=0'));
        $this->assertStatus(422, $this->outbox('?limit=101'));
    }
}
