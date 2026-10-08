<?php

declare(strict_types=1);

namespace HrPayroll\Service;

use RuntimeException;

/**
 * Internal signal: the key was stored by an earlier request. Never leaves
 * IdempotencyService.
 */
final class IdempotencyKeyTaken extends RuntimeException
{
}
