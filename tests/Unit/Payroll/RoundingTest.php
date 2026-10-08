<?php

declare(strict_types=1);

namespace HrPayroll\Tests\Unit\Payroll;

use HrPayroll\Domain\Payroll\Rounding;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RoundingTest extends TestCase
{
    /**
     * @return iterable<string, array{int, int, int}>
     */
    public static function divisions(): iterable
    {
        yield 'exact' => [10, 5, 2];
        yield 'below half rounds down' => [1, 3, 0];
        yield 'above half rounds up' => [2, 3, 1];
        yield 'exactly half rounds up' => [1, 2, 1];
        yield 'another exact half' => [5, 2, 3];
        yield 'zero numerator' => [0, 7, 0];
        yield 'large rupiah amount' => [8_500_000 * 23, 173, 1_130_058];
    }

    #[DataProvider('divisions')]
    public function testDivideHalfUp(int $numerator, int $denominator, int $expected): void
    {
        self::assertSame($expected, Rounding::divideHalfUp($numerator, $denominator));
    }

    public function testBasisPointsRoundHalfUp(): void
    {
        self::assertSame(1, Rounding::basisPoints(50, 100));
        self::assertSame(0, Rounding::basisPoints(49, 100));
        self::assertSame(24_102, Rounding::basisPoints(10_042_300, 24), '24,101.52 rounds up');
    }

    public function testRejectsZeroDenominator(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Rounding::divideHalfUp(1, 0);
    }

    public function testRejectsNegativeNumerator(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Rounding::divideHalfUp(-1, 2);
    }
}
