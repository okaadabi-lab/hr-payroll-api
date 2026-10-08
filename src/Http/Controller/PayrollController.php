<?php

declare(strict_types=1);

namespace HrPayroll\Http\Controller;

use HrPayroll\Domain\Exception\ValidationFailed;
use HrPayroll\Domain\Payroll\PayrollMonth;
use HrPayroll\Http\Input;
use HrPayroll\Http\Json;
use HrPayroll\Query\ListQuery;
use HrPayroll\Http\Paginator;
use HrPayroll\Repository\PayrollPeriodRepository;
use HrPayroll\Repository\PayslipRepository;
use HrPayroll\Service\IdempotencyService;
use HrPayroll\Service\PayrollService;
use HrPayroll\Service\PayslipService;
use InvalidArgumentException;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class PayrollController extends Controller
{
    public function __construct(
        private readonly PayrollService $payroll,
        private readonly PayslipService $payslips,
        private readonly IdempotencyService $idempotency,
    ) {
    }

    public function list(Request $request, Response $response): Response
    {
        $query = ListQuery::fromParams(
            self::params($request),
            PayrollPeriodRepository::SORTABLE,
            '-period',
            PayrollPeriodRepository::FILTERS,
        );

        return Paginator::respond($request, $response, $query, $this->payroll->list(self::principal($request), $query));
    }

    /**
     * @param array<string, string> $args
     */
    public function get(Request $request, Response $response, array $args): Response
    {
        return Json::response($response, ['data' => $this->payroll->get(self::principal($request), self::id($args))]);
    }

    public function create(Request $request, Response $response): Response
    {
        $input = new Input(Json::body($request));
        $period = $input->string('period', 7);
        $input->validate();
        try {
            $month = PayrollMonth::fromString((string) $period);
        } catch (InvalidArgumentException $e) {
            throw ValidationFailed::single('period', $e->getMessage());
        }

        return Json::response($response, ['data' => $this->payroll->create(self::principal($request), $month)], 201);
    }

    /**
     * @param array<string, string> $args
     */
    public function calculate(Request $request, Response $response, array $args): Response
    {
        $principal = self::principal($request);
        $body = Json::body($request);
        $input = new Input($body);
        $note = $input->string('note', 255, false);
        $input->validate();

        $id = self::id($args);
        $key = $request->getHeaderLine('Idempotency-Key');
        $result = $this->idempotency->run(
            $principal,
            $key === '' ? null : $key,
            IdempotencyService::requestHash('POST', $request->getUri()->getPath(), $body),
            fn (): array => [200, ['data' => $this->payroll->calculate($principal, $id, $note)]],
        );

        return Json::response($response, $result['body'], $result['status'])
            ->withHeader('Idempotent-Replayed', $result['replayed'] ? 'true' : 'false');
    }

    /**
     * @param array<string, string> $args
     */
    public function approve(Request $request, Response $response, array $args): Response
    {
        $period = $this->payroll->approve(self::principal($request), self::id($args));

        return Json::response($response, ['data' => $period]);
    }

    /**
     * @param array<string, string> $args
     */
    public function markPaid(Request $request, Response $response, array $args): Response
    {
        $period = $this->payroll->markPaid(self::principal($request), self::id($args));

        return Json::response($response, ['data' => $period]);
    }

    /**
     * @param array<string, string> $args
     */
    public function payslips(Request $request, Response $response, array $args): Response
    {
        $query = ListQuery::fromParams(
            self::params($request),
            PayslipRepository::SORTABLE,
            'employee',
            PayslipRepository::FILTERS,
        );
        $page = $this->payslips->listForPeriod(self::principal($request), self::id($args), $query);

        return Paginator::respond($request, $response, $query, $page);
    }
}
