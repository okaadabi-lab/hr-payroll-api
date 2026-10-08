<?php

declare(strict_types=1);

use HrPayroll\Container;
use HrPayroll\Infrastructure\Migrator;

require dirname(__DIR__) . '/vendor/autoload.php';

// The schema is rebuilt once per run; each integration test then truncates and reseeds.
$container = Container::fromEnvironment(dirname(__DIR__));
if (!str_ends_with($container->config()->dbName, '_test')) {
    fwrite(STDERR, "Refusing to run tests against a database whose name does not end in _test.\n");
    exit(1);
}
$migrator = new Migrator($container->db(), dirname(__DIR__) . '/migrations');
$migrator->dropAllTables();
$migrator->migrate();
