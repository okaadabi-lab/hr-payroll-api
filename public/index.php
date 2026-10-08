<?php

declare(strict_types=1);

use HrPayroll\Container;
use HrPayroll\Http\AppFactory;

require dirname(__DIR__) . '/vendor/autoload.php';

AppFactory::create(Container::fromEnvironment(dirname(__DIR__)))->run();
