<?php

declare(strict_types=1);

namespace HrPayroll\Infrastructure;

use Monolog\Formatter\JsonFormatter;
use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Monolog\Logger;
use Psr\Log\LoggerInterface;

final class LoggerFactory
{
    public static function create(Config $config, RequestContext $context): LoggerInterface
    {
        $handler = new StreamHandler('php://stdout', self::level($config->logLevel));
        $handler->setFormatter(new JsonFormatter());

        $logger = new Logger('hr-payroll-api');
        $logger->pushHandler($handler);
        $logger->pushProcessor(static function ($record) use ($context) {
            $record->extra['request_id'] = $context->requestId();

            return $record;
        });

        return $logger;
    }

    private static function level(string $name): Level
    {
        foreach (Level::cases() as $level) {
            if (strcasecmp($level->getName(), $name) === 0) {
                return $level;
            }
        }

        return Level::Info;
    }
}
