<?php

declare(strict_types=1);

namespace HrPayroll\Query;

/**
 * @template T
 */
final class Page
{
    /**
     * @param list<T> $items
     */
    public function __construct(public readonly array $items, public readonly int $total)
    {
    }
}
