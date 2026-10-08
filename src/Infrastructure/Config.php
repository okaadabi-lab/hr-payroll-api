<?php

declare(strict_types=1);

namespace HrPayroll\Infrastructure;

use RuntimeException;

final class Config
{
    public function __construct(
        public readonly string $env,
        public readonly bool $debug,
        public readonly string $appUrl,
        public readonly string $timezone,
        public readonly string $logLevel,
        public readonly string $dbHost,
        public readonly int $dbPort,
        public readonly string $dbName,
        public readonly string $dbUser,
        public readonly string $dbPassword,
        public readonly string $jwtSecret,
        public readonly string $jwtIssuer,
        public readonly int $accessTokenTtl,
        public readonly int $refreshTokenTtl,
        public readonly int $loginMaxAttempts,
        public readonly int $loginWindowSeconds,
    ) {
        if (strlen($jwtSecret) < 32) {
            throw new RuntimeException('JWT_SECRET must be at least 32 characters long.');
        }
    }

    /**
     * @param array<string, mixed> $env
     */
    public static function fromEnvironment(array $env): self
    {
        $get = static function (string $key, ?string $default = null) use ($env): string {
            $value = $env[$key] ?? $default;
            if (!is_string($value)) {
                throw new RuntimeException("Missing required environment variable {$key}.");
            }

            return $value;
        };

        return new self(
            env: $get('APP_ENV', 'production'),
            debug: filter_var($get('APP_DEBUG', 'false'), FILTER_VALIDATE_BOOL),
            appUrl: rtrim($get('APP_URL', 'http://localhost:8082'), '/'),
            timezone: $get('APP_TIMEZONE', 'Asia/Jakarta'),
            logLevel: $get('LOG_LEVEL', 'info'),
            dbHost: $get('DB_HOST', '127.0.0.1'),
            dbPort: (int) $get('DB_PORT', '3306'),
            dbName: $get('DB_DATABASE'),
            dbUser: $get('DB_USERNAME'),
            dbPassword: $get('DB_PASSWORD', ''),
            jwtSecret: $get('JWT_SECRET'),
            jwtIssuer: $get('JWT_ISSUER', 'hr-payroll-api'),
            accessTokenTtl: (int) $get('ACCESS_TOKEN_TTL', '900'),
            refreshTokenTtl: (int) $get('REFRESH_TOKEN_TTL', '2592000'),
            loginMaxAttempts: (int) $get('LOGIN_MAX_ATTEMPTS', '5'),
            loginWindowSeconds: (int) $get('LOGIN_WINDOW_SECONDS', '900'),
        );
    }
}
