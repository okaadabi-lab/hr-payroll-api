<?php

declare(strict_types=1);

namespace HrPayroll\Domain\Payroll;

use InvalidArgumentException;

/**
 * All money is integer rupiah. Every computed line goes through this one
 * rounding rule (half up to the nearest rupiah) so results are reproducible.
 */
final class Rounding
{
    public static function divideHalfUp(int $numerator, int $denominator): int
    {
        if ($numerator < 0 || $denominator <= 0) {
            throw new InvalidArgumentException('Expected a non-negative numerator and a positive denominator.');
        }

        return intdiv(2 * $numerator + $denominator, 2 * $denominator);
    }

    public static function basisPoints(int $amount, int $basisPoints): int
    {
        return self::divideHalfUp($amount * $basisPoints, 10_000);
    }
}
