<?php

declare(strict_types=1);

namespace HrPayroll\Http\Controller;

use HrPayroll\Http\Input;
use HrPayroll\Http\Json;
use HrPayroll\Service\AuthService;
use HrPayroll\Service\UserService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class AuthController extends Controller
{
    public function __construct(private readonly AuthService $auth, private readonly UserService $users)
    {
    }

    public function login(Request $request, Response $response): Response
    {
        $input = new Input(Json::body($request));
        $email = $input->email('email');
        $password = $input->string('password', 200);
        $input->validate();

        return Json::response($response, $this->auth->login((string) $email, (string) $password, self::ip($request)));
    }

    public function refresh(Request $request, Response $response): Response
    {
        $input = new Input(Json::body($request));
        $token = $input->string('refresh_token', 200);
        $input->validate();

        return Json::response($response, $this->auth->refresh((string) $token, self::ip($request)));
    }

    public function logout(Request $request, Response $response): Response
    {
        $input = new Input(Json::body($request));
        $token = $input->string('refresh_token', 200);
        $input->validate();
        $this->auth->logout((string) $token);

        return $response->withStatus(204);
    }

    public function me(Request $request, Response $response): Response
    {
        return Json::response($response, ['data' => $this->users->me(self::principal($request))]);
    }

    private static function ip(Request $request): ?string
    {
        $ip = $request->getServerParams()['REMOTE_ADDR'] ?? null;

        return is_string($ip) ? $ip : null;
    }
}
