<?php

declare(strict_types=1);

namespace HrPayroll\Http\Middleware;

use HrPayroll\Service\ApiKeyAuthenticator;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class ApiKeyMiddleware implements MiddlewareInterface
{
    public const CLIENT = 'api_client';

    public function __construct(private readonly ApiKeyAuthenticator $authenticator, private readonly string $scope)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $key = $request->getHeaderLine('X-Api-Key');
        $client = $this->authenticator->authenticate($key === '' ? null : $key, $this->scope);

        return $handler->handle($request->withAttribute(self::CLIENT, $client));
    }
}
