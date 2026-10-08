<?php

declare(strict_types=1);

namespace HrPayroll\Http\Middleware;

use HrPayroll\Infrastructure\RequestContext;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;

/**
 * Outermost middleware: assigns the request id, echoes it in X-Request-Id and
 * writes one access log line per request.
 */
final class RequestContextMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly RequestContext $context, private readonly LoggerInterface $logger)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $incoming = $request->getHeaderLine('X-Request-Id');
        $requestId = preg_match('/^[A-Za-z0-9._-]{8,64}$/', $incoming) === 1 ? $incoming : bin2hex(random_bytes(16));
        $ip = $request->getServerParams()['REMOTE_ADDR'] ?? null;
        $this->context->begin($requestId, is_string($ip) ? $ip : null);

        $started = hrtime(true);
        $response = $handler->handle($request);

        $this->logger->info('request', [
            'method' => $request->getMethod(),
            'path' => $request->getUri()->getPath(),
            'status' => $response->getStatusCode(),
            'duration_ms' => round((hrtime(true) - $started) / 1e6, 1),
        ]);

        return $response->withHeader('X-Request-Id', $requestId);
    }
}
