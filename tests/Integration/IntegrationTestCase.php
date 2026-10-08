<?php

declare(strict_types=1);

namespace HrPayroll\Tests\Integration;

use HrPayroll\Container;
use HrPayroll\Http\AppFactory;
use HrPayroll\Infrastructure\Database;
use HrPayroll\Infrastructure\Seeder;
use HrPayroll\Tests\Support\FixedClock;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\App;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Factory\StreamFactory;

/**
 * Boots the real Slim application against the hr_payroll_test database and
 * resets it to the seeded fixture before every test.
 */
abstract class IntegrationTestCase extends TestCase
{
    /** Thursday 10 September 2026, 09:00 in Jakarta. */
    protected const NOW = '2026-09-10 02:00:00';

    protected Container $container;
    protected FixedClock $clock;
    protected Database $db;
    /** @var App<ContainerInterface|null> */
    protected App $app;
    /** @var array<string, int> */
    protected array $ids = [];
    protected string $apiKey;

    /** @var array<string, string> */
    private array $tokens = [];

    protected function setUp(): void
    {
        $this->clock = new FixedClock(self::NOW);
        $this->container = Container::fromEnvironment(dirname(__DIR__, 2), $this->clock);
        $this->db = $this->container->db();
        $this->resetDatabase();

        $seeder = new Seeder($this->db);
        $seeder->run();
        $this->ids = $seeder->ids;
        $this->apiKey = $seeder->createApiClient('n8n-test', 'outbox:read');

        $this->app = AppFactory::create($this->container);
    }

    private function resetDatabase(): void
    {
        $tables = $this->db->fetchAll(
            "SELECT table_name AS name FROM information_schema.tables
             WHERE table_schema = DATABASE() AND table_name <> 'schema_migrations'"
        );
        $pdo = $this->db->pdo();
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach ($tables as $table) {
            $pdo->exec('TRUNCATE TABLE `' . $table['name'] . '`');
        }
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    }

    /**
     * @param array<string, mixed>|list<mixed>|string|null $body array is sent as JSON, string as-is
     * @param array<string, string>                        $headers
     */
    protected function request(
        string $method,
        string $path,
        array|string|null $body = null,
        ?string $as = null,
        array $headers = [],
    ): ResponseInterface {
        $request = $this->buildRequest($method, $path, $as);
        if (is_array($body)) {
            $request = $request
                ->withHeader('Content-Type', 'application/json')
                ->withBody((new StreamFactory())->createStream((string) json_encode($body)));
        } elseif (is_string($body)) {
            $request = $request->withBody((new StreamFactory())->createStream($body));
        }
        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        return $this->app->handle($request);
    }

    /**
     * @param array<string, mixed>|list<mixed>|string|null $body
     * @param array<string, string>                        $headers
     */
    protected function post(
        string $path,
        array|string|null $body,
        ?string $as = null,
        array $headers = [],
    ): ResponseInterface {
        return $this->request('POST', $path, $body, $as, $headers);
    }

    protected function get(string $path, ?string $as = null): ResponseInterface
    {
        return $this->request('GET', $path, null, $as);
    }

    protected function buildRequest(string $method, string $path, ?string $as = null): ServerRequestInterface
    {
        $server = ['REMOTE_ADDR' => '203.0.113.7'];
        $request = (new ServerRequestFactory())->createServerRequest($method, $path, $server);
        parse_str((string) parse_url($path, PHP_URL_QUERY), $query);
        $request = $request->withQueryParams($query);
        if ($as !== null) {
            $request = $request->withHeader('Authorization', 'Bearer ' . $this->tokenFor($as));
        }

        return $request;
    }

    /**
     * @return array<string, mixed>
     */
    protected function json(ResponseInterface $response): array
    {
        $decoded = json_decode((string) $response->getBody(), true);
        self::assertIsArray($decoded, 'Response body is not JSON: ' . $response->getBody());

        return $decoded;
    }

    protected function assertStatus(int $expected, ResponseInterface $response, string $message = ''): void
    {
        self::assertSame($expected, $response->getStatusCode(), trim($message . ' ' . $response->getBody()));
    }

    /**
     * @return array<string, mixed>
     */
    protected function login(string $email, string $password = Seeder::PASSWORD): array
    {
        $response = $this->request('POST', '/api/v1/auth/login', ['email' => $email, 'password' => $password]);
        $this->assertStatus(200, $response);

        return $this->json($response);
    }

    /**
     * Seeded users are referred to by their short key: admin, admin2, hr, dina, raka, budi, sari, nina.
     */
    protected function tokenFor(string $user): string
    {
        if (!isset($this->tokens[$user])) {
            $row = $this->db->fetchOne('SELECT email FROM users WHERE id = ?', [$this->ids['user_' . $user]]);
            $this->tokens[$user] = (string) $this->login((string) ($row['email'] ?? ''))['access_token'];
        }

        return $this->tokens[$user];
    }

    /**
     * Moves time forward. Cached access tokens are dropped because they may
     * have expired; tests that need an old token keep their own copy.
     */
    protected function advanceClock(string $modifier): void
    {
        $this->clock->advance($modifier);
        $this->tokens = [];
    }

    protected function auditCount(string $action): int
    {
        return $this->countRows('SELECT COUNT(*) FROM audit_logs WHERE action = ?', [$action]);
    }

    protected function periodStatus(int $periodId): ?string
    {
        $row = $this->db->fetchOne('SELECT status FROM payroll_periods WHERE id = ?', [$periodId]);

        return $row === null ? null : (string) $row['status'];
    }

    /**
     * @param list<scalar|null> $params
     */
    protected function countRows(string $sql, array $params = []): int
    {
        return $this->db->fetchInt($sql, $params);
    }
}
