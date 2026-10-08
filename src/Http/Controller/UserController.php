<?php

declare(strict_types=1);

namespace HrPayroll\Http\Controller;

use HrPayroll\Domain\Role;
use HrPayroll\Http\Input;
use HrPayroll\Http\Json;
use HrPayroll\Service\UserService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class UserController extends Controller
{
    public function __construct(private readonly UserService $users)
    {
    }

    public function create(Request $request, Response $response): Response
    {
        $input = new Input(Json::body($request));
        $email = $input->email('email');
        $password = $input->string('password', 200, true, 12);
        $role = $input->oneOf('role', array_column(Role::cases(), 'value'));
        $employeeId = $input->isNull('employee_id') ? null : $input->int('employee_id', 1, PHP_INT_MAX, false);
        $input->validate();

        $user = $this->users->create(
            self::principal($request),
            (string) $email,
            (string) $password,
            Role::from((string) $role),
            $employeeId,
        );

        return Json::response($response, ['data' => $user], 201);
    }
}
