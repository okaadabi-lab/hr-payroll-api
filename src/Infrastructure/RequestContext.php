<?php

declare(strict_types=1);

namespace HrPayroll\Infrastructure;

/**
 * Holds per-request values that logging and auditing need but that should not
 * be threaded through every method signature.
 */
final class RequestContext
{
    private ?string $requestId = null;
    private ?string $ip = null;

    public function begin(string $requestId, ?string $ip): void
    {
        $this->requestId = $requestId;
        $this->ip = $ip;
    }

    public function requestId(): ?string
    {
        return $this->requestId;
    }

    public function ip(): ?string
    {
        return $this->ip;
    }
}
