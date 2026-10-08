<?php

declare(strict_types=1);

namespace HrPayroll\Http\Controller;

use HrPayroll\Domain\Exception\ValidationFailed;
use HrPayroll\Http\Input;
use HrPayroll\Http\Json;
use HrPayroll\Http\Middleware\ApiKeyMiddleware;
use HrPayroll\Service\OutboxService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class IntegrationController extends Controller
{
    public function __construct(private readonly OutboxService $outbox)
    {
    }

    public function outbox(Request $request, Response $response): Response
    {
        $params = $request->getQueryParams();
        $after = self::nonNegative($params['after'] ?? '0', 'after', PHP_INT_MAX);
        $limit = self::nonNegative($params['limit'] ?? '50', 'limit', OutboxService::MAX_LIMIT);
        if ($limit === 0) {
            throw ValidationFailed::single('limit', 'Must be between 1 and ' . OutboxService::MAX_LIMIT . '.');
        }

        return Json::response($response, $this->outbox->pending($after, $limit));
    }

    public function ack(Request $request, Response $response): Response
    {
        $input = new Input(Json::body($request));
        $ids = $input->idList('ids', OutboxService::MAX_LIMIT);
        $input->validate();

        /** @var array{id: int} $client */
        $client = $request->getAttribute(ApiKeyMiddleware::CLIENT);

        return Json::response($response, ['data' => $this->outbox->ack($client['id'], $ids)]);
    }

    private static function nonNegative(mixed $value, string $field, int $max): int
    {
        if (!is_string($value) || preg_match('/^\d{1,18}$/', $value) !== 1 || (int) $value > $max) {
            throw ValidationFailed::single($field, "Must be an integer between 0 and {$max}.");
        }

        return (int) $value;
    }
}
