<?php

declare(strict_types=1);

namespace HrPayroll\Http\Controller;

use HrPayroll\Http\Input;
use HrPayroll\Http\Json;
use HrPayroll\Http\ListQuery;
use HrPayroll\Http\Paginator;
use HrPayroll\Repository\DepartmentRepository;
use HrPayroll\Service\DepartmentService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class DepartmentController extends Controller
{
    public function __construct(private readonly DepartmentService $departments)
    {
    }

    public function list(Request $request, Response $response): Response
    {
        self::principal($request);
        $query = ListQuery::fromParams(self::params($request), DepartmentRepository::SORTABLE, 'name', ['search']);

        return Paginator::respond($request, $response, $query, $this->departments->list($query));
    }

    /**
     * @param array<string, string> $args
     */
    public function get(Request $request, Response $response, array $args): Response
    {
        self::principal($request);

        return Json::response($response, ['data' => $this->departments->get(self::id($args))]);
    }

    public function create(Request $request, Response $response): Response
    {
        $input = new Input(Json::body($request));
        $code = $input->string('code', 20);
        $name = $input->string('name', 100);
        $input->validate();

        $department = $this->departments->create(self::principal($request), strtoupper((string) $code), (string) $name);

        return Json::response($response, ['data' => $department], 201);
    }

    /**
     * @param array<string, string> $args
     */
    public function update(Request $request, Response $response, array $args): Response
    {
        $input = new Input(Json::body($request));
        $code = $input->string('code', 20, false);
        $name = $input->string('name', 100, false);
        $input->validate();

        $department = $this->departments->update(
            self::principal($request),
            self::id($args),
            $code === null ? null : strtoupper($code),
            $name,
        );

        return Json::response($response, ['data' => $department]);
    }
}
