<?php

declare(strict_types=1);

namespace HrPayroll\Domain;

use DateTimeImmutable;

final class CalendarDate
{
    /**
     * True for a real calendar date in Y-m-d format (rejects 2026-02-30).
     */
    public static function isValid(string $value): bool
    {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $parsed !== false && $parsed->format('Y-m-d') === $value;
    }
}
