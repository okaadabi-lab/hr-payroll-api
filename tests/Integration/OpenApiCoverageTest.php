<?php

declare(strict_types=1);

namespace HrPayroll\Tests\Integration;

use Symfony\Component\Yaml\Yaml;

/**
 * Fails when a route is registered in Slim but not documented in
 * docs/openapi.yaml (or documented but no longer registered).
 */
final class OpenApiCoverageTest extends IntegrationTestCase
{
    /**
     * @return list<string> "METHOD /path" with path parameters normalised to {id}
     */
    private function registeredRoutes(): array
    {
        $routes = [];
        foreach ($this->app->getRouteCollector()->getRoutes() as $route) {
            $path = (string) preg_replace('/\{(\w+)(:[^}]+)?\}/', '{$1}', $route->getPattern());
            foreach ($route->getMethods() as $method) {
                $routes[] = strtoupper($method) . ' ' . $path;
            }
        }
        sort($routes);

        return $routes;
    }

    /**
     * @return list<string>
     */
    private function documentedRoutes(): array
    {
        $spec = Yaml::parseFile(dirname(__DIR__, 2) . '/docs/openapi.yaml');
        self::assertIsArray($spec);
        self::assertSame('3.1.0', $spec['openapi']);

        $routes = [];
        foreach ($spec['paths'] as $path => $operations) {
            foreach (array_keys($operations) as $method) {
                if (in_array($method, ['get', 'post', 'put', 'patch', 'delete'], true)) {
                    $routes[] = strtoupper($method) . ' ' . $path;
                }
            }
        }
        sort($routes);

        return $routes;
    }

    public function testEveryRegisteredRouteIsDocumented(): void
    {
        $missing = array_diff($this->registeredRoutes(), $this->documentedRoutes());

        self::assertSame([], array_values($missing), 'Routes missing from docs/openapi.yaml');
    }

    public function testEveryDocumentedRouteExists(): void
    {
        $stale = array_diff($this->documentedRoutes(), $this->registeredRoutes());

        self::assertSame([], array_values($stale), 'Documented routes that are not registered');
    }
}
