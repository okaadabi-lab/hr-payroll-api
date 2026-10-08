<?php

declare(strict_types=1);

namespace HrPayroll\Http\Controller;

use HrPayroll\Http\Json;
use HrPayroll\Query\ListQuery;
use HrPayroll\Http\Paginator;
use HrPayroll\Repository\PayslipRepository;
use HrPayroll\Service\PayslipService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class PayslipController extends Controller
{
    public function __construct(private readonly PayslipService $payslips)
    {
    }

    public function list(Request $request, Response $response): Response
    {
        $query = ListQuery::fromParams(
            self::params($request),
            PayslipRepository::SORTABLE,
            '-period',
            PayslipRepository::FILTERS,
        );

        $page = $this->payslips->list(self::principal($request), $query);

        return Paginator::respond($request, $response, $query, $page);
    }

    /**
     * @param array<string, string> $args
     */
    public function get(Request $request, Response $response, array $args): Response
    {
        return Json::response($response, ['data' => $this->payslips->get(self::principal($request), self::id($args))]);
    }
}
