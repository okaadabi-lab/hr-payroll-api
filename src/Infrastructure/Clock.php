<?php

declare(strict_types=1);

namespace HrPayroll\Infrastructure;

use DateTimeImmutable;

interface Clock
{
    public const DB_FORMAT = 'Y-m-d H:i:s';

    /**
     * Current instant in UTC.
     */
    public function now(): DateTimeImmutable;
}
