<?php

declare(strict_types=1);

namespace HrPayroll\Tests\Integration;

final class HttpBehaviourTest extends IntegrationTestCase
{
    public function testHealthChecksTheDatabaseWithoutAuth(): void
    {
        $response = $this->request('GET', '/health');

        $this->assertStatus(200, $response);
        self::assertSame(['status' => 'ok', 'checks' => ['database' => 'ok']], $this->json($response));
    }

    public function testEveryResponseCarriesARequestId(): void
    {
        $generated = $this->get('/health')->getHeaderLine('X-Request-Id');
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $generated);
        self::assertNotSame('', $this->request('GET', '/api/v1/nowhere')->getHeaderLine('X-Request-Id'));

        $echoed = $this->request('GET', '/health', null, null, ['X-Request-Id' => 'client-abc-123']);
        self::assertSame('client-abc-123', $echoed->getHeaderLine('X-Request-Id'));

        $unsafe = $this->request('GET', '/health', null, null, ['X-Request-Id' => '<script>alert(1)</script>']);
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $unsafe->getHeaderLine('X-Request-Id'));
    }

    public function testUnknownRouteAndWrongMethodAreProblemDocuments(): void
    {
        $notFound = $this->request('GET', '/api/v1/nowhere');
        $this->assertStatus(404, $notFound);
        self::assertSame('application/problem+json', $notFound->getHeaderLine('Content-Type'));

        $wrongMethod = $this->request('DELETE', '/api/v1/employees', null, 'hr');
        $this->assertStatus(405, $wrongMethod);
        self::assertStringContainsString('GET', $wrongMethod->getHeaderLine('Allow'));
    }

    public function testMalformedJsonIsA400(): void
    {
        $response = $this->post('/api/v1/departments', '{"code": ', 'hr', ['Content-Type' => 'application/json']);

        $this->assertStatus(400, $response);
        self::assertSame('invalid_json', $this->json($response)['code']);
    }

    public function testDatabaseErrorsDoNotLeakToClients(): void
    {
        $this->db->pdo()->exec('ALTER TABLE departments RENAME TO departments_renamed');
        try {
            $response = $this->get('/api/v1/departments', 'hr');
        } finally {
            $this->db->pdo()->exec('ALTER TABLE departments_renamed RENAME TO departments');
        }

        $this->assertStatus(500, $response);
        $body = (string) $response->getBody();
        self::assertSame('An unexpected error occurred.', $this->json($response)['detail']);
        self::assertStringNotContainsStringIgnoringCase('SQLSTATE', $body);
        self::assertStringNotContainsString('departments_renamed', $body);
        self::assertStringNotContainsString('.php', $body);
    }

    public function testValidationProblemListsEveryInvalidField(): void
    {
        $response = $this->post('/api/v1/employees', ['full_name' => '', 'base_salary' => -5], 'hr');

        $this->assertStatus(422, $response);
        $problem = $this->json($response);
        self::assertSame('validation_failed', $problem['code']);
        self::assertSame(422, $problem['status']);
        $fields = array_column($problem['errors'], 'field');
        $required = ['employee_number', 'full_name', 'email', 'position', 'department_id', 'base_salary', 'hired_at'];
        foreach ($required as $field) {
            self::assertContains($field, $fields);
        }
    }
}
