<?php

declare(strict_types=1);

namespace HrPayroll\Domain;

enum Role: string
{
    case Admin = 'admin';
    case Hr = 'hr';
    case Manager = 'manager';
    case Employee = 'employee';

    public function isHrOrAdmin(): bool
    {
        return $this === self::Admin || $this === self::Hr;
    }
}
