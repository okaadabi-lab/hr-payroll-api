<?php

declare(strict_types=1);

namespace HrPayroll\Tests\Integration;

use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Factory\StreamFactory;
use Slim\Psr7\UploadedFile;

final class AttendanceTest extends IntegrationTestCase
{
    private function import(string $csv, string $as = 'hr'): ResponseInterface
    {
        return $this->post('/api/v1/attendance/import', $csv, $as, ['Content-Type' => 'text/csv']);
    }

    public function testCreateAndUniquePerEmployeeAndDate(): void
    {
        $record = [
            'employee_id' => $this->ids['emp_budi'],
            'work_date' => '2026-09-01',
            'status' => 'present',
            'clock_in' => '08:00',
            'clock_out' => '17:15',
        ];

        $created = $this->post('/api/v1/attendance', $record, 'hr');
        $this->assertStatus(201, $created);
        self::assertSame('17:15:00', $this->json($created)['data']['clock_out']);

        $duplicate = $this->post('/api/v1/attendance', $record, 'hr');
        $this->assertStatus(409, $duplicate);
        self::assertSame('duplicate_attendance', $this->json($duplicate)['code']);
    }

    public function testClockTimesMustBeConsistent(): void
    {
        $base = ['employee_id' => $this->ids['emp_budi'], 'work_date' => '2026-09-01'];

        $invalid = [
            'clock-out before clock-in' => ['status' => 'present', 'clock_in' => '17:00', 'clock_out' => '08:00'],
            'clock time on a sick day' => ['status' => 'sick', 'clock_in' => '08:00'],
            'clock-out without clock-in' => ['status' => 'present', 'clock_out' => '17:00'],
        ];
        foreach ($invalid as $case => $fields) {
            $this->assertStatus(422, $this->post('/api/v1/attendance', [...$base, ...$fields], 'hr'), $case);
        }
    }

    public function testEmployeesCannotWriteAttendanceForOthers(): void
    {
        $this->assertStatus(403, $this->post('/api/v1/attendance', [
            'employee_id' => $this->ids['emp_budi'],
            'work_date' => '2026-09-01',
            'status' => 'present',
        ], 'budi'));
    }

    public function testSelfServiceClockInAndOutUseJakartaTime(): void
    {
        $in = $this->post('/api/v1/attendance/clock-in', null, 'budi');
        $this->assertStatus(201, $in);
        self::assertSame('2026-09-10', $this->json($in)['data']['work_date']);
        self::assertSame('09:00:00', $this->json($in)['data']['clock_in']);

        $this->assertStatus(409, $this->post('/api/v1/attendance/clock-in', null, 'budi'));

        $this->advanceClock('+8 hours 30 minutes');
        $out = $this->post('/api/v1/attendance/clock-out', null, 'budi');
        $this->assertStatus(200, $out);
        self::assertSame('17:30:00', $this->json($out)['data']['clock_out']);

        $this->assertStatus(409, $this->post('/api/v1/attendance/clock-out', null, 'budi'));
    }

    public function testClockOutWithoutClockInIsAConflict(): void
    {
        $this->assertStatus(409, $this->post('/api/v1/attendance/clock-out', null, 'sari'));
    }

    public function testUsersWithoutAnEmployeeCannotClockIn(): void
    {
        $this->assertStatus(403, $this->post('/api/v1/attendance/clock-in', null, 'hr'));
    }

    public function testCsvImportStoresValidRowsAndReportsInvalidOnes(): void
    {
        $this->db->insert('attendance_records', [
            'employee_id' => $this->ids['emp_sari'],
            'work_date' => '2026-09-03',
            'status' => 'present',
        ]);
        $csv = implode("\n", [
            'employee_number,work_date,status,clock_in,clock_out,note',
            'EMP-002,2026-09-01,present,08:00,17:00,',
            'EMP-002,2026-09-02,absent,,,No call no show',
            'EMP-999,2026-09-01,present,08:00,17:00,',
            'EMP-003,2026-09-31,present,08:00,17:00,',
            'EMP-003,2026-09-02,holiday,,,',
            'EMP-003,2026-09-03,sick,,,',
            'EMP-002,2026-09-01,leave,,,',
            'EMP-005,2026-09-01,present,17:00,08:00,',
            '"EMP-005",2026-09-02,sick,,,"Flu, doctor note"',
        ]);

        $response = $this->import($csv);
        $this->assertStatus(200, $response);
        $result = $this->json($response)['data'];

        self::assertSame(9, $result['total_rows']);
        self::assertSame(3, $result['imported']);
        self::assertSame(6, $result['failed']);
        self::assertSame(
            [
                [4, 'employee_number'],
                [5, 'work_date'],
                [6, 'status'],
                [7, 'work_date'],
                [8, 'work_date'],
                [9, 'clock_out'],
            ],
            array_map(static fn (array $e): array => [$e['line'], $e['field']], $result['errors']),
        );
        self::assertStringContainsString('Duplicate of line 2', $result['errors'][4]['message']);

        self::assertSame(4, $this->countRows('SELECT COUNT(*) FROM attendance_records'));
        $note = $this->db->fetchOne(
            'SELECT note FROM attendance_records WHERE employee_id = ? AND work_date = ?',
            [$this->ids['emp_nina'], '2026-09-02'],
        );
        self::assertSame('Flu, doctor note', $note['note'] ?? null);
        self::assertSame(1, $this->auditCount('attendance.imported'));
    }

    public function testCsvImportAcceptsMultipartUploads(): void
    {
        $file = (new StreamFactory())->createStream("employee_number,work_date,status\nEMP-002,2026-09-01,present\n");
        $request = $this->buildRequest('POST', '/api/v1/attendance/import', 'hr')
            ->withUploadedFiles(['file' => new UploadedFile($file, 'september.csv', 'text/csv', $file->getSize())]);

        $response = $this->app->handle($request);

        $this->assertStatus(200, $response);
        self::assertSame(1, $this->json($response)['data']['imported']);
    }

    public function testCsvWithWrongHeaderIsRejectedAsAWhole(): void
    {
        $response = $this->import("name,date,status\nBudi,2026-09-01,present");

        $this->assertStatus(400, $response);
        self::assertSame('invalid_csv', $this->json($response)['code']);
        self::assertSame(0, $this->countRows('SELECT COUNT(*) FROM attendance_records'));
    }

    public function testCsvImportRequiresHrOrAdmin(): void
    {
        $this->assertStatus(403, $this->import("employee_number,work_date,status\nEMP-002,2026-09-01,present", 'dina'));
    }

    public function testListFiltersByPeriodStatusAndDepartment(): void
    {
        $this->import(implode("\n", [
            'employee_number,work_date,status',
            'EMP-002,2026-08-31,present',
            'EMP-002,2026-09-01,absent',
            'EMP-005,2026-09-01,absent',
        ]));

        $result = $this->json($this->request(
            'GET',
            "/api/v1/attendance?period=2026-09&status=absent&department_id={$this->ids['d_ops']}",
            null,
            'hr',
        ));

        self::assertSame(['Budi Santoso'], array_column($result['data'], 'employee_name'));
    }
}
