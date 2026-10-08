<?php

declare(strict_types=1);

namespace HrPayroll\Http;

/**
 * RFC 7807 problem details body.
 */
final class Problem
{
    /**
     * @param list<array{field: string, message: string}> $errors
     */
    public function __construct(
        public readonly int $status,
        public readonly string $code,
        public readonly string $title,
        public readonly string $detail,
        public readonly array $errors = [],
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(string $instance, ?string $requestId): array
    {
        $body = [
            'type' => 'urn:hr-payroll:problem:' . $this->code,
            'title' => $this->title,
            'status' => $this->status,
            'detail' => $this->detail,
            'instance' => $instance,
            'code' => $this->code,
            'request_id' => $requestId,
        ];
        if ($this->errors !== []) {
            $body['errors'] = $this->errors;
        }

        return $body;
    }
}
