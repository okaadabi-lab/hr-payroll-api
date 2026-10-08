<?php

declare(strict_types=1);

namespace HrPayroll\Http\Controller;

use HrPayroll\Domain\AttendanceStatus;
use HrPayroll\Domain\Exception\BadRequest;
use HrPayroll\Http\Input;
use HrPayroll\Http\Json;
use HrPayroll\Http\ListQuery;
use HrPayroll\Http\Paginator;
use HrPayroll\Repository\AttendanceRepository;
use HrPayroll\Service\AttendanceImportService;
use HrPayroll\Service\AttendanceService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\UploadedFileInterface;

final class AttendanceController extends Controller
{
    private const MAX_CSV_BYTES = 2 * 1024 * 1024;

    public function __construct(
        private readonly AttendanceService $attendance,
        private readonly AttendanceImportService $import,
    ) {
    }

    public function list(Request $request, Response $response): Response
    {
        $query = ListQuery::fromParams(
            self::params($request),
            AttendanceRepository::SORTABLE,
            '-work_date',
            AttendanceRepository::FILTERS,
        );

        $page = $this->attendance->list(self::principal($request), $query);

        return Paginator::respond($request, $response, $query, $page);
    }

    /**
     * @param array<string, string> $args
     */
    public function get(Request $request, Response $response, array $args): Response
    {
        $record = $this->attendance->get(self::principal($request), self::id($args));

        return Json::response($response, ['data' => $record]);
    }

    public function create(Request $request, Response $response): Response
    {
        $input = new Input(Json::body($request));
        $employeeId = $input->int('employee_id');
        $date = $input->date('work_date');
        $fields = self::readFields($input);
        $input->validate();

        $record = $this->attendance->create(self::principal($request), [
            'employee_id' => (int) $employeeId,
            'work_date' => (string) $date,
            ...$fields,
        ]);

        return Json::response($response, ['data' => $record], 201);
    }

    /**
     * @param array<string, string> $args
     */
    public function update(Request $request, Response $response, array $args): Response
    {
        $input = new Input(Json::body($request));
        $fields = self::readFields($input);
        $input->validate();

        return Json::response($response, [
            'data' => $this->attendance->update(self::principal($request), self::id($args), $fields),
        ]);
    }

    /**
     * @param array<string, string> $args
     */
    public function delete(Request $request, Response $response, array $args): Response
    {
        $this->attendance->delete(self::principal($request), self::id($args));

        return $response->withStatus(204);
    }

    public function clockIn(Request $request, Response $response): Response
    {
        return Json::response($response, ['data' => $this->attendance->clockIn(self::principal($request))], 201);
    }

    public function clockOut(Request $request, Response $response): Response
    {
        return Json::response($response, ['data' => $this->attendance->clockOut(self::principal($request))]);
    }

    /**
     * Accepts either multipart/form-data with a "file" field or a raw text/csv body.
     */
    public function import(Request $request, Response $response): Response
    {
        $principal = self::principal($request);
        $file = $request->getUploadedFiles()['file'] ?? null;
        if ($file instanceof UploadedFileInterface) {
            if ($file->getError() !== UPLOAD_ERR_OK || ($file->getSize() ?? 0) > self::MAX_CSV_BYTES) {
                throw new BadRequest('The uploaded file is missing or larger than 2 MB.', 'invalid_csv');
            }
            $csv = (string) $file->getStream();
        } elseif (str_starts_with($request->getHeaderLine('Content-Type'), 'text/csv')) {
            $csv = (string) $request->getBody();
            if (strlen($csv) > self::MAX_CSV_BYTES) {
                throw new BadRequest('The CSV body is larger than 2 MB.', 'invalid_csv');
            }
        } else {
            throw new BadRequest('Send a multipart "file" field or a text/csv body.', 'invalid_csv');
        }

        // 200 with a per-row report: the upload itself succeeded even when some rows were rejected.
        return Json::response($response, ['data' => $this->import->import($principal, $csv)]);
    }

    /**
     * @return array{status: string, clock_in: ?string, clock_out: ?string, note: ?string}
     */
    private static function readFields(Input $input): array
    {
        return [
            'status' => (string) $input->oneOf('status', array_column(AttendanceStatus::cases(), 'value')),
            'clock_in' => $input->time('clock_in', false),
            'clock_out' => $input->time('clock_out', false),
            'note' => $input->isNull('note') ? null : $input->string('note', 255, false),
        ];
    }
}
