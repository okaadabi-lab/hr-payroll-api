<?php

declare(strict_types=1);

namespace HrPayroll\Http\Controller;

use HrPayroll\Http\Json;
use HrPayroll\Infrastructure\Database;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;
use Throwable;

final class HealthController
{
    public function __construct(private readonly Database $db, private readonly LoggerInterface $logger)
    {
    }

    public function __invoke(Request $request, Response $response): Response
    {
        try {
            $databaseUp = $this->db->ping();
        } catch (Throwable $e) {
            $this->logger->error('health_check_failed', ['exception' => $e]);
            $databaseUp = false;
        }

        return Json::response(
            $response,
            ['status' => $databaseUp ? 'ok' : 'unavailable', 'checks' => ['database' => $databaseUp ? 'ok' : 'down']],
            $databaseUp ? 200 : 503,
        );
    }
}
