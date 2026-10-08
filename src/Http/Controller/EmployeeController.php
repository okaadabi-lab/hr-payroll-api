<?php

declare(strict_types=1);

namespace HrPayroll\Http\Controller;

use HrPayroll\Http\Input;
use HrPayroll\Http\Json;
use HrPayroll\Http\ListQuery;
use HrPayroll\Http\Paginator;
use HrPayroll\Repository\EmployeeRepository;
use HrPayroll\Service\EmployeeService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class EmployeeController extends Controller
{
    public function __construct(private readonly EmployeeService $employees)
    {
    }

    public function list(Request $request, Response $response): Response
    {
        $query = ListQuery::fromParams(
            self::params($request),
            EmployeeRepository::SORTABLE,
            'name',
            EmployeeRepository::FILTERS,
        );

        $page = $this->employees->list(self::principal($request), $query);

        return Paginator::respond($request, $response, $query, $page);
    }

    /**
     * @param array<string, string> $args
     */
    public function get(Request $request, Response $response, array $args): Response
    {
        return Json::response($response, ['data' => $this->employees->get(self::principal($request), self::id($args))]);
    }

    public function create(Request $request, Response $response): Response
    {
        $values = self::read(new Input(Json::body($request)), true);
        $employee = $this->employees->create(self::principal($request), $values);

        return Json::response($response, ['data' => $employee], 201);
    }

    /**
     * @param array<string, string> $args
     */
    public function update(Request $request, Response $response, array $args): Response
    {
        $values = self::read(new Input(Json::body($request)), false);
        $employee = $this->employees->update(self::principal($request), self::id($args), $values);

        return Json::response($response, ['data' => $employee]);
    }

    /**
     * @return array<string, scalar|null>
     */
    private static function read(Input $input, bool $creating): array
    {
        $values = [
            'employee_number' => $input->string('employee_number', 20, $creating),
            'full_name' => $input->string('full_name', 150, $creating),
            'email' => $input->email('email', $creating),
            'phone' => $input->isNull('phone') ? null : $input->string('phone', 20, false, 6),
            'position' => $input->string('position', 100, $creating),
            'department_id' => $input->int('department_id', 1, PHP_INT_MAX, $creating),
            'manager_id' => $input->isNull('manager_id') ? null : $input->int('manager_id', 1, PHP_INT_MAX, false),
            'base_salary' => $input->money('base_salary', $creating),
            'fixed_allowances' => $input->money('fixed_allowances', false),
            'hired_at' => $input->date('hired_at', $creating),
            'status' => $input->oneOf('status', ['active', 'inactive'], false),
        ];
        if (is_string($values['phone']) && preg_match('/^\+?[0-9]{6,19}$/', $values['phone']) !== 1) {
            $input->addError('phone', 'Must contain digits only, optionally starting with +.');
        }
        $input->validate();

        if ($creating) {
            $values['fixed_allowances'] ??= 0;
            $values['status'] ??= 'active';

            return $values;
        }

        // On update, only send fields the client provided (explicit nulls clear phone or manager).
        return array_filter(
            $values,
            static fn (mixed $value, string $field): bool => $value !== null || $input->isNull($field),
            ARRAY_FILTER_USE_BOTH,
        );
    }
}
