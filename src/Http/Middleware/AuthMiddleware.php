<?php

declare(strict_types=1);

namespace HrPayroll\Http\Middleware;

use HrPayroll\Auth\TokenIssuer;
use HrPayroll\Domain\Exception\Unauthenticated;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class AuthMiddleware implements MiddlewareInterface
{
    public const PRINCIPAL = 'principal';

    public function __construct(private readonly TokenIssuer $tokens)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $header = $request->getHeaderLine('Authorization');
        if (preg_match('/^Bearer\s+(\S+)$/i', $header, $matches) !== 1) {
            throw new Unauthenticated('A Bearer access token is required.', 'missing_token');
        }

        return $handler->handle($request->withAttribute(self::PRINCIPAL, $this->tokens->verify($matches[1])));
    }
}
