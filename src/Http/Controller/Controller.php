<?php

declare(strict_types=1);

namespace HrPayroll\Http\Controller;

use HrPayroll\Auth\Principal;
use HrPayroll\Domain\Exception\Unauthenticated;
use HrPayroll\Http\Middleware\AuthMiddleware;
use Psr\Http\Message\ServerRequestInterface;

abstract class Controller
{
    protected static function principal(ServerRequestInterface $request): Principal
    {
        $principal = $request->getAttribute(AuthMiddleware::PRINCIPAL);
        if (!$principal instanceof Principal) {
            throw new Unauthenticated('Authentication is required.');
        }

        return $principal;
    }

    /**
     * @param array<string, string> $args
     */
    protected static function id(array $args, string $name = 'id'): int
    {
        return (int) ($args[$name] ?? 0);
    }

    /**
     * @return array<string, mixed>
     */
    protected static function params(ServerRequestInterface $request): array
    {
        return $request->getQueryParams();
    }
}
