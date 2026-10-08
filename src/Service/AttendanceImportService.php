<?php

declare(strict_types=1);

namespace HrPayroll\Service;

use HrPayroll\Auth\Principal;
use HrPayroll\Domain\AttendanceStatus;
use HrPayroll\Domain\Exception\BadRequest;
use HrPayroll\Domain\Exception\Conflict;
use HrPayroll\Domain\Role;
use HrPayroll\Http\Input;
use HrPayroll\Infrastructure\Database;
use HrPayroll\Repository\AttendanceRepository;
use HrPayroll\Repository\EmployeeRepository;
use PDOException;

/**
 * Bulk attendance import from CSV.
 *
 * Strategy: validate every row first, then insert all valid rows in a single
 * transaction. Invalid rows are skipped and reported with their line number.
 * Because the valid rows are written atomically, an unexpected failure (for
 * example a concurrent insert hitting the unique key) leaves nothing behind and
 * the same file can simply be uploaded again; there is never a half-imported
 * file to reconcile.
 */
final class AttendanceImportService
{
    public const MAX_ROWS = 5000;
    private const COLUMNS = ['employee_number', 'work_date', 'status', 'clock_in', 'clock_out', 'note'];

    public function __construct(
        private readonly Database $db,
        private readonly AttendanceRepository $attendance,
        private readonly EmployeeRepository $employees,
        private readonly PeriodLock $periodLock,
        private readonly AuditLogger $audit,
    ) {
    }

    /**
     * @return array{
     *     total_rows: int,
     *     imported: int,
     *     failed: int,
     *     errors: list<array{line: int, field: string, message: string}>
     * }
     */
    public function import(Principal $actor, string $csv): array
    {
        $actor->requireRole(Role::Admin, Role::Hr);
        $rows = $this->parse($csv);

        return $this->db->transactional(function () use ($actor, $rows): array {
            $errors = [];
            $valid = [];
            $seen = [];
            $employeeIds = [];
            $lockedMonths = [];
            foreach ($rows as $line => $row) {
                $rowErrors = $this->validateRow($row, $employeeIds);
                if ($rowErrors === []) {
                    $employeeId = $employeeIds[$row['employee_number']];
                    $key = $employeeId . '|' . $row['work_date'];
                    $month = substr($row['work_date'], 0, 7);
                    $lockedMonths[$month] ??= $this->periodLock->isLocked($row['work_date']);
                    if (isset($seen[$key])) {
                        $rowErrors[] = ['field' => 'work_date', 'message' => "Duplicate of line {$seen[$key]}."];
                    } elseif ($lockedMonths[$month]) {
                        $rowErrors[] = ['field' => 'work_date', 'message' => 'Payroll period is locked.'];
                    } elseif ($this->attendance->existsFor($employeeId, $row['work_date'])) {
                        $rowErrors[] = ['field' => 'work_date', 'message' => 'Attendance already exists.'];
                    }
                    $seen[$key] ??= $line;
                }
                if ($rowErrors !== []) {
                    foreach ($rowErrors as $error) {
                        $errors[] = ['line' => $line, ...$error];
                    }
                    continue;
                }
                $valid[] = [
                    'employee_id' => $employeeIds[$row['employee_number']],
                    'work_date' => $row['work_date'],
                    'status' => $row['status'],
                    'clock_in' => self::time($row['clock_in']),
                    'clock_out' => self::time($row['clock_out']),
                    'note' => $row['note'] === '' ? null : $row['note'],
                ];
            }

            $months = array_unique(array_map(static fn (array $v): string => substr($v['work_date'], 0, 7), $valid));
            foreach ($months as $month) {
                $this->periodLock->assertWritable($actor, $month . '-01');
            }

            try {
                foreach ($valid as $values) {
                    $this->attendance->create($values);
                }
            } catch (PDOException $e) {
                if ($e->getCode() === '23000') {
                    throw new Conflict(
                        'Attendance changed while importing. Nothing was imported; retry the upload.',
                        'import_conflict',
                    );
                }
                throw $e;
            }

            $summary = [
                'total_rows' => count($rows),
                'imported' => count($valid),
                'failed' => count(array_unique(array_column($errors, 'line'))),
                'errors' => $errors,
            ];
            $this->audit->record($actor, 'attendance.imported', 'attendance', null, null, [
                'total_rows' => $summary['total_rows'],
                'imported' => $summary['imported'],
                'failed' => $summary['failed'],
            ]);

            return $summary;
        });
    }

    /**
     * @return array<int, array<string, string>> keyed by 1-based line number in the file
     */
    private function parse(string $csv): array
    {
        $csv = preg_replace('/^\xEF\xBB\xBF/', '', $csv) ?? $csv;
        $lines = preg_split('/\r\n|\n|\r/', trim($csv)) ?: [];
        if ($lines === [] || $lines[0] === '') {
            throw new BadRequest('The CSV file is empty.', 'invalid_csv');
        }

        $header = array_map(
            static fn (?string $h): string => strtolower(trim((string) $h)),
            str_getcsv($lines[0], ',', '"', ''),
        );
        $missing = array_diff(['employee_number', 'work_date', 'status'], $header);
        $unknown = array_diff($header, self::COLUMNS);
        if ($missing !== [] || $unknown !== []) {
            throw new BadRequest(
                'CSV header must contain employee_number, work_date, status and optionally clock_in, clock_out, note.',
                'invalid_csv',
            );
        }
        if (count($lines) - 1 > self::MAX_ROWS) {
            throw new BadRequest(sprintf('A single import is limited to %d rows.', self::MAX_ROWS), 'invalid_csv');
        }

        $rows = [];
        foreach (array_slice($lines, 1, null, true) as $index => $line) {
            if (trim($line) === '') {
                continue;
            }
            $cells = str_getcsv($line, ',', '"', '');
            $row = [];
            foreach (self::COLUMNS as $column) {
                $position = array_search($column, $header, true);
                $row[$column] = $position === false ? '' : trim((string) ($cells[$position] ?? ''));
            }
            $rows[$index + 1] = $row;
        }

        return $rows;
    }

    /**
     * @param array<string, string> $row
     * @param array<string, int>    $employeeIds cache of employee_number => id, filled as rows are validated
     * @return list<array{field: string, message: string}>
     */
    private function validateRow(array $row, array &$employeeIds): array
    {
        $errors = [];
        $number = $row['employee_number'];
        if ($number === '') {
            $errors[] = ['field' => 'employee_number', 'message' => 'Required.'];
        } elseif (!isset($employeeIds[$number])) {
            $employee = $this->employees->findByNumber($number);
            if ($employee === null) {
                $errors[] = ['field' => 'employee_number', 'message' => 'Unknown employee.'];
            } else {
                $employeeIds[$number] = (int) $employee['id'];
            }
        }

        if (!Input::isDate($row['work_date'])) {
            $errors[] = ['field' => 'work_date', 'message' => 'Must be a date in YYYY-MM-DD format.'];
        }
        if (AttendanceStatus::tryFrom($row['status']) === null) {
            $errors[] = ['field' => 'status', 'message' => 'Must be one of: present, leave, sick, absent.'];
        }
        foreach (['clock_in', 'clock_out'] as $field) {
            if ($row[$field] !== '' && self::time($row[$field]) === null) {
                $errors[] = ['field' => $field, 'message' => 'Must be a time in HH:MM format.'];
            }
        }
        if (mb_strlen($row['note']) > 255) {
            $errors[] = ['field' => 'note', 'message' => 'Must be at most 255 characters.'];
        }
        if ($errors === []) {
            $errors = AttendanceService::consistencyErrors([
                'status' => $row['status'],
                'clock_in' => self::time($row['clock_in']),
                'clock_out' => self::time($row['clock_out']),
            ]);
        }

        return $errors;
    }

    private static function time(string $value): ?string
    {
        if (preg_match('/^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/', $value) !== 1) {
            return null;
        }

        return strlen($value) === 5 ? $value . ':00' : $value;
    }
}
