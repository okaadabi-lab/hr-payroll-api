<?php

declare(strict_types=1);

namespace HrPayroll\Http\Controller;

use HrPayroll\Http\ListQuery;
use HrPayroll\Http\Paginator;
use HrPayroll\Repository\AuditLogRepository;
use HrPayroll\Service\AuditQueryService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class AuditController extends Controller
{
    public function __construct(private readonly AuditQueryService $audit)
    {
    }

    public function list(Request $request, Response $response): Response
    {
        $query = ListQuery::fromParams(
            self::params($request),
            AuditLogRepository::SORTABLE,
            '-created_at',
            AuditLogRepository::FILTERS,
        );

        return Paginator::respond($request, $response, $query, $this->audit->list(self::principal($request), $query));
    }
}
