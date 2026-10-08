<?php

declare(strict_types=1);

namespace HrPayroll\Infrastructure;

final class JsonCodec
{
    private const FLAGS = JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

    /**
     * @param array<mixed> $data
     */
    public static function encode(array $data): string
    {
        return json_encode($data, self::FLAGS);
    }
}
