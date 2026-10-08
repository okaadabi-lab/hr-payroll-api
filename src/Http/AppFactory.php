<?php

declare(strict_types=1);

namespace HrPayroll\Http;

use HrPayroll\Container;
use HrPayroll\Http\Middleware\ProblemMiddleware;
use HrPayroll\Http\Middleware\RequestContextMiddleware;
use Psr\Container\ContainerInterface;
use Slim\App;
use Slim\Factory\AppFactory as SlimAppFactory;
use Slim\Psr7\Factory\ResponseFactory;

final class AppFactory
{
    /**
     * @return App<ContainerInterface|null>
     */
    public static function create(Container $container): App
    {
        $responseFactory = new ResponseFactory();
        $app = SlimAppFactory::create($responseFactory);

        Routes::register($app, $container);

        // Slim runs middleware last-added-first: request context -> problem mapping -> routing.
        $app->addRoutingMiddleware();
        $app->add(new ProblemMiddleware(
            $responseFactory,
            $container->logger(),
            $container->requestContext(),
            $container->config()->debug,
        ));
        $app->add(new RequestContextMiddleware($container->requestContext(), $container->logger()));

        return $app;
    }
}
