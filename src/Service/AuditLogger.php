<?php

declare(strict_types=1);

namespace HrPayroll\Service;

use HrPayroll\Auth\Principal;
use HrPayroll\Infrastructure\Clock;
use HrPayroll\Infrastructure\RequestContext;
use HrPayroll\Repository\AuditLogRepository;

final class AuditLogger
{
    public function __construct(
        private readonly AuditLogRepository $logs,
        private readonly RequestContext $context,
        private readonly Clock $clock,
    ) {
    }

    /**
     * Call inside the same transaction as the change being audited.
     *
     * @param array<string, mixed>|null $before
     * @param array<string, mixed>|null $after
     */
    public function record(
        ?Principal $actor,
        string $action,
        string $entityType,
        ?int $entityId,
        ?array $before = null,
        ?array $after = null,
    ): void {
        $this->logs->record(
            $actor?->userId,
            $action,
            $entityType,
            $entityId,
            $before,
            $after,
            $this->context->ip(),
            $this->clock->now()->format(Clock::DB_FORMAT),
        );
    }
}
