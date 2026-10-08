<?php

declare(strict_types=1);

namespace HrPayroll\Service;

use HrPayroll\Auth\Principal;
use HrPayroll\Domain\Role;
use HrPayroll\Http\ListQuery;
use HrPayroll\Http\Page;
use HrPayroll\Repository\AuditLogRepository;

final class AuditQueryService
{
    public function __construct(private readonly AuditLogRepository $logs)
    {
    }

    /**
     * @return Page<array<string, mixed>>
     */
    public function list(Principal $actor, ListQuery $query): Page
    {
        $actor->requireRole(Role::Admin);

        return $this->logs->list($query);
    }
}
