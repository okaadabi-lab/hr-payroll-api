<?php

declare(strict_types=1);

namespace HrPayroll\Http\Controller;

use HrPayroll\Domain\OvertimeStatus;
use HrPayroll\Http\Input;
use HrPayroll\Http\Json;
use HrPayroll\Http\ListQuery;
use HrPayroll\Http\Paginator;
use HrPayroll\Repository\OvertimeRepository;
use HrPayroll\Service\OvertimeService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class OvertimeController extends Controller
{
    public function __construct(private readonly OvertimeService $overtime)
    {
    }

    public function list(Request $request, Response $response): Response
    {
        $query = ListQuery::fromParams(
            self::params($request),
            OvertimeRepository::SORTABLE,
            '-work_date',
            OvertimeRepository::FILTERS,
        );

        $page = $this->overtime->list(self::principal($request), $query);

        return Paginator::respond($request, $response, $query, $page);
    }

    /**
     * @param array<string, string> $args
     */
    public function get(Request $request, Response $response, array $args): Response
    {
        return Json::response($response, ['data' => $this->overtime->get(self::principal($request), self::id($args))]);
    }

    public function submit(Request $request, Response $response): Response
    {
        $input = new Input(Json::body($request));
        $employeeId = $input->int('employee_id', 1, PHP_INT_MAX, false);
        $date = $input->date('work_date');
        $minutes = $input->hoursAsMinutes('hours');
        $reason = $input->string('reason', 500, true, 3);
        $input->validate();

        $created = $this->overtime->submit(
            self::principal($request),
            $employeeId,
            (string) $date,
            (int) $minutes,
            (string) $reason,
        );

        return Json::response($response, ['data' => $created], 201);
    }

    /**
     * @param array<string, string> $args
     */
    public function approve(Request $request, Response $response, array $args): Response
    {
        return $this->decide($request, $response, $args, OvertimeStatus::Approved);
    }

    /**
     * @param array<string, string> $args
     */
    public function reject(Request $request, Response $response, array $args): Response
    {
        return $this->decide($request, $response, $args, OvertimeStatus::Rejected);
    }

    /**
     * @param array<string, string> $args
     */
    private function decide(Request $request, Response $response, array $args, OvertimeStatus $decision): Response
    {
        $input = new Input(Json::body($request));
        $note = $input->string('note', 255, false);
        $input->validate();

        return Json::response($response, [
            'data' => $this->overtime->decide(self::principal($request), self::id($args), $decision, $note),
        ]);
    }
}
