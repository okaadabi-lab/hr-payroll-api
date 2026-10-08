<?php

declare(strict_types=1);

namespace HrPayroll\Http\Middleware;

use HrPayroll\Domain\Exception\DomainError;
use HrPayroll\Domain\Exception\TooManyRequests;
use HrPayroll\Domain\Exception\ValidationFailed;
use HrPayroll\Http\Json;
use HrPayroll\Http\Problem;
use HrPayroll\Infrastructure\RequestContext;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use Slim\Exception\HttpMethodNotAllowedException;
use Slim\Exception\HttpNotFoundException;
use Throwable;

/**
 * Converts every exception into an RFC 7807 response. Only DomainError
 * messages are shown to clients; anything else is logged with its trace and
 * answered with a generic 500 so SQL errors and internals never leak.
 */
final class ProblemMiddleware implements MiddlewareInterface
{
    private const TITLES = [
        400 => 'Bad Request',
        401 => 'Unauthorized',
        403 => 'Forbidden',
        404 => 'Not Found',
        405 => 'Method Not Allowed',
        409 => 'Conflict',
        422 => 'Unprocessable Content',
        429 => 'Too Many Requests',
        500 => 'Internal Server Error',
    ];

    public function __construct(
        private readonly ResponseFactoryInterface $responses,
        private readonly LoggerInterface $logger,
        private readonly RequestContext $context,
        private readonly bool $debug,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        try {
            return $handler->handle($request);
        } catch (Throwable $e) {
            return $this->toResponse($request, $e);
        }
    }

    private function toResponse(ServerRequestInterface $request, Throwable $e): ResponseInterface
    {
        $headers = [];
        if ($e instanceof DomainError) {
            $problem = new Problem(
                $e->httpStatus(),
                $e->errorCode(),
                self::TITLES[$e->httpStatus()] ?? 'Error',
                $e->getMessage(),
                $e instanceof ValidationFailed ? $e->errors() : [],
            );
            if ($e instanceof TooManyRequests) {
                $headers['Retry-After'] = (string) $e->retryAfterSeconds();
            }
            if ($e->httpStatus() === 401) {
                $headers['WWW-Authenticate'] = 'Bearer';
            }
        } elseif ($e instanceof HttpNotFoundException) {
            $problem = new Problem(404, 'route_not_found', 'Not Found', 'No route matches this path.');
        } elseif ($e instanceof HttpMethodNotAllowedException) {
            $problem = new Problem(405, 'method_not_allowed', 'Method Not Allowed', 'Method not allowed on this path.');
            $headers['Allow'] = implode(', ', $e->getAllowedMethods());
        } else {
            $this->logger->error('unhandled_exception', ['exception' => $e]);
            $problem = new Problem(
                500,
                'internal_error',
                'Internal Server Error',
                $this->debug ? get_class($e) . ': ' . $e->getMessage() : 'An unexpected error occurred.',
            );
        }

        $response = Json::response(
            $this->responses->createResponse(),
            $problem->toArray($request->getUri()->getPath(), $this->context->requestId()),
            $problem->status,
            'application/problem+json',
        );
        foreach ($headers as $name => $value) {
            $response = $response->withHeader($name, $value);
        }

        return $response;
    }
}
