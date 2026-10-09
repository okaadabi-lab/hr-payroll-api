<?php

declare(strict_types=1);

// Sends one calculate request from a separate PHP process, so tests can run two
// requests with the same Idempotency-Key at the same time. Prints "<status> <replayed>".

use HrPayroll\Container;
use HrPayroll\Http\AppFactory;
use HrPayroll\Tests\Support\FixedClock;
use Slim\Psr7\Factory\ServerRequestFactory;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$now = $argv[1] ?? '';
$path = $argv[2] ?? '';
$token = $argv[3] ?? '';
$key = $argv[4] ?? '';

$app = AppFactory::create(Container::fromEnvironment(dirname(__DIR__, 2), new FixedClock($now)));
$request = (new ServerRequestFactory())->createServerRequest('POST', $path)
    ->withHeader('Authorization', "Bearer {$token}")
    ->withHeader('Idempotency-Key', $key);
$response = $app->handle($request);

echo $response->getStatusCode(), ' ', $response->getHeaderLine('Idempotent-Replayed');
