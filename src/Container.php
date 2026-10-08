<?php

declare(strict_types=1);

namespace HrPayroll;

use DateTimeZone;
use Dotenv\Dotenv;
use HrPayroll\Auth\TokenIssuer;
use HrPayroll\Domain\Payroll\PayrollCalculator;
use HrPayroll\Http\Controller\AttendanceController;
use HrPayroll\Http\Controller\AuditController;
use HrPayroll\Http\Controller\AuthController;
use HrPayroll\Http\Controller\DepartmentController;
use HrPayroll\Http\Controller\EmployeeController;
use HrPayroll\Http\Controller\HealthController;
use HrPayroll\Http\Controller\IntegrationController;
use HrPayroll\Http\Controller\OvertimeController;
use HrPayroll\Http\Controller\PayrollController;
use HrPayroll\Http\Controller\PayslipController;
use HrPayroll\Http\Controller\SettingsController;
use HrPayroll\Http\Controller\UserController;
use HrPayroll\Infrastructure\Clock;
use HrPayroll\Infrastructure\Config;
use HrPayroll\Infrastructure\Database;
use HrPayroll\Infrastructure\LoggerFactory;
use HrPayroll\Infrastructure\RequestContext;
use HrPayroll\Infrastructure\SystemClock;
use HrPayroll\Repository\ApiClientRepository;
use HrPayroll\Repository\AttendanceRepository;
use HrPayroll\Repository\AuditLogRepository;
use HrPayroll\Repository\BpjsSettingRepository;
use HrPayroll\Repository\DepartmentRepository;
use HrPayroll\Repository\EmployeeRepository;
use HrPayroll\Repository\HolidayRepository;
use HrPayroll\Repository\IdempotencyRepository;
use HrPayroll\Repository\LoginAttemptRepository;
use HrPayroll\Repository\OutboxRepository;
use HrPayroll\Repository\OvertimeRepository;
use HrPayroll\Repository\PayrollPeriodRepository;
use HrPayroll\Repository\PayslipRepository;
use HrPayroll\Repository\RefreshTokenRepository;
use HrPayroll\Repository\UserRepository;
use HrPayroll\Service\ApiKeyAuthenticator;
use HrPayroll\Service\AttendanceImportService;
use HrPayroll\Service\AttendanceService;
use HrPayroll\Service\AuditLogger;
use HrPayroll\Service\AuditQueryService;
use HrPayroll\Service\AuthService;
use HrPayroll\Service\DepartmentService;
use HrPayroll\Service\EmployeeService;
use HrPayroll\Service\IdempotencyService;
use HrPayroll\Service\LoginThrottle;
use HrPayroll\Service\OutboxService;
use HrPayroll\Service\OvertimeService;
use HrPayroll\Service\PayrollService;
use HrPayroll\Service\PayslipService;
use HrPayroll\Service\PeriodLock;
use HrPayroll\Service\SettingsService;
use HrPayroll\Service\UserService;
use Psr\Log\LoggerInterface;

/**
 * Hand-written composition root. Every object graph is built here with plain
 * constructor calls, so there is no reflection or autowiring to reason about.
 * Shared instances are memoised per container (one container per process).
 */
final class Container
{
    /** @var array<string, object> */
    private array $instances = [];
    private readonly RequestContext $requestContext;

    public function __construct(private readonly Config $config, private readonly Clock $clock = new SystemClock())
    {
        $this->requestContext = new RequestContext();
    }

    public static function fromEnvironment(string $rootDir): self
    {
        Dotenv::createImmutable($rootDir)->safeLoad();
        $processEnv = getenv();

        // Variables set by the shell or PHPUnit win over .env.
        return new self(Config::fromEnvironment(array_merge($_ENV, $processEnv)));
    }

    /**
     * @template T of object
     * @param class-string<T> $id
     * @param callable(): T   $factory
     * @return T
     */
    private function shared(string $id, callable $factory): object
    {
        $instance = $this->instances[$id] ??= $factory();
        assert($instance instanceof $id);

        return $instance;
    }

    public function config(): Config
    {
        return $this->config;
    }

    public function clock(): Clock
    {
        return $this->clock;
    }

    public function requestContext(): RequestContext
    {
        return $this->requestContext;
    }

    public function db(): Database
    {
        return $this->shared(Database::class, fn () => Database::fromConfig($this->config));
    }

    public function logger(): LoggerInterface
    {
        return $this->shared(
            LoggerInterface::class,
            fn () => LoggerFactory::create($this->config, $this->requestContext),
        );
    }

    public function tokenIssuer(): TokenIssuer
    {
        return $this->shared(TokenIssuer::class, fn () => new TokenIssuer(
            $this->config->jwtSecret,
            $this->config->jwtIssuer,
            $this->config->accessTokenTtl,
            $this->clock,
        ));
    }

    // Repositories

    public function users(): UserRepository
    {
        return $this->shared(UserRepository::class, fn () => new UserRepository($this->db()));
    }

    public function employees(): EmployeeRepository
    {
        return $this->shared(EmployeeRepository::class, fn () => new EmployeeRepository($this->db()));
    }

    public function departments(): DepartmentRepository
    {
        return $this->shared(DepartmentRepository::class, fn () => new DepartmentRepository($this->db()));
    }

    public function attendance(): AttendanceRepository
    {
        return $this->shared(AttendanceRepository::class, fn () => new AttendanceRepository($this->db()));
    }

    public function overtime(): OvertimeRepository
    {
        return $this->shared(OvertimeRepository::class, fn () => new OvertimeRepository($this->db()));
    }

    public function holidays(): HolidayRepository
    {
        return $this->shared(HolidayRepository::class, fn () => new HolidayRepository($this->db()));
    }

    public function bpjsSettings(): BpjsSettingRepository
    {
        return $this->shared(BpjsSettingRepository::class, fn () => new BpjsSettingRepository($this->db()));
    }

    public function periods(): PayrollPeriodRepository
    {
        return $this->shared(PayrollPeriodRepository::class, fn () => new PayrollPeriodRepository($this->db()));
    }

    public function payslips(): PayslipRepository
    {
        return $this->shared(PayslipRepository::class, fn () => new PayslipRepository($this->db()));
    }

    public function outbox(): OutboxRepository
    {
        return $this->shared(OutboxRepository::class, fn () => new OutboxRepository($this->db()));
    }

    public function apiClients(): ApiClientRepository
    {
        return $this->shared(ApiClientRepository::class, fn () => new ApiClientRepository($this->db()));
    }

    // Services

    public function auditLogger(): AuditLogger
    {
        return $this->shared(AuditLogger::class, fn () => new AuditLogger(
            new AuditLogRepository($this->db()),
            $this->requestContext,
            $this->clock,
        ));
    }

    public function periodLock(): PeriodLock
    {
        return $this->shared(PeriodLock::class, fn () => new PeriodLock($this->periods(), $this->auditLogger()));
    }

    public function authService(): AuthService
    {
        return $this->shared(AuthService::class, fn () => new AuthService(
            $this->db(),
            $this->users(),
            new RefreshTokenRepository($this->db()),
            $this->tokenIssuer(),
            new LoginThrottle(
                new LoginAttemptRepository($this->db()),
                $this->clock,
                $this->config->loginMaxAttempts,
                $this->config->loginWindowSeconds,
            ),
            $this->auditLogger(),
            $this->clock,
            $this->config->refreshTokenTtl,
        ));
    }

    public function apiKeyAuthenticator(): ApiKeyAuthenticator
    {
        return $this->shared(
            ApiKeyAuthenticator::class,
            fn () => new ApiKeyAuthenticator($this->apiClients(), $this->clock),
        );
    }

    public function userService(): UserService
    {
        return $this->shared(UserService::class, fn () => new UserService(
            $this->db(),
            $this->users(),
            $this->employees(),
            $this->auditLogger(),
        ));
    }

    public function employeeService(): EmployeeService
    {
        return $this->shared(EmployeeService::class, fn () => new EmployeeService(
            $this->db(),
            $this->employees(),
            $this->departments(),
            $this->periodLock(),
            $this->auditLogger(),
        ));
    }

    public function attendanceService(): AttendanceService
    {
        return $this->shared(AttendanceService::class, fn () => new AttendanceService(
            $this->db(),
            $this->attendance(),
            $this->employees(),
            $this->employeeService(),
            $this->periodLock(),
            $this->auditLogger(),
            $this->clock,
            new DateTimeZone($this->config->timezone),
        ));
    }

    public function overtimeService(): OvertimeService
    {
        return $this->shared(OvertimeService::class, fn () => new OvertimeService(
            $this->db(),
            $this->overtime(),
            $this->employees(),
            $this->holidays(),
            $this->employeeService(),
            $this->periodLock(),
            $this->auditLogger(),
            $this->clock,
        ));
    }

    public function payrollService(): PayrollService
    {
        return $this->shared(PayrollService::class, fn () => new PayrollService(
            $this->db(),
            $this->periods(),
            $this->payslips(),
            $this->employees(),
            $this->attendance(),
            $this->overtime(),
            $this->holidays(),
            $this->bpjsSettings(),
            $this->outbox(),
            new PayrollCalculator(),
            $this->auditLogger(),
            $this->clock,
            $this->config->appUrl,
        ));
    }

    public function payslipService(): PayslipService
    {
        return $this->shared(PayslipService::class, fn () => new PayslipService($this->payslips(), $this->periods()));
    }

    // Controllers

    public function healthController(): HealthController
    {
        return new HealthController($this->db(), $this->logger());
    }

    public function authController(): AuthController
    {
        return new AuthController($this->authService(), $this->userService());
    }

    public function userController(): UserController
    {
        return new UserController($this->userService());
    }

    public function departmentController(): DepartmentController
    {
        return new DepartmentController(new DepartmentService($this->db(), $this->departments(), $this->auditLogger()));
    }

    public function employeeController(): EmployeeController
    {
        return new EmployeeController($this->employeeService());
    }

    public function attendanceController(): AttendanceController
    {
        return new AttendanceController(
            $this->attendanceService(),
            new AttendanceImportService(
                $this->db(),
                $this->attendance(),
                $this->employees(),
                $this->periodLock(),
                $this->auditLogger(),
            ),
        );
    }

    public function overtimeController(): OvertimeController
    {
        return new OvertimeController($this->overtimeService());
    }

    public function payrollController(): PayrollController
    {
        return new PayrollController(
            $this->payrollService(),
            $this->payslipService(),
            new IdempotencyService($this->db(), new IdempotencyRepository($this->db()), $this->clock),
        );
    }

    public function payslipController(): PayslipController
    {
        return new PayslipController($this->payslipService());
    }

    public function settingsController(): SettingsController
    {
        return new SettingsController(new SettingsService(
            $this->db(),
            $this->holidays(),
            $this->bpjsSettings(),
            $this->periodLock(),
            $this->auditLogger(),
        ));
    }

    public function auditController(): AuditController
    {
        return new AuditController(new AuditQueryService(new AuditLogRepository($this->db())));
    }

    public function integrationController(): IntegrationController
    {
        return new IntegrationController(new OutboxService($this->db(), $this->outbox(), $this->clock));
    }
}
