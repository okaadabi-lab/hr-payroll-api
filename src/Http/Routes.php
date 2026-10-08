<?php

declare(strict_types=1);

namespace HrPayroll\Http;

use HrPayroll\Container;
use HrPayroll\Http\Controller\AttendanceController;
use HrPayroll\Http\Controller\AuditController;
use HrPayroll\Http\Controller\AuthController;
use HrPayroll\Http\Controller\DepartmentController;
use HrPayroll\Http\Controller\EmployeeController;
use HrPayroll\Http\Controller\IntegrationController;
use HrPayroll\Http\Controller\OvertimeController;
use HrPayroll\Http\Controller\PayrollController;
use HrPayroll\Http\Controller\PayslipController;
use HrPayroll\Http\Controller\SettingsController;
use HrPayroll\Http\Controller\UserController;
use HrPayroll\Http\Middleware\ApiKeyMiddleware;
use HrPayroll\Http\Middleware\AuthMiddleware;
use Psr\Container\ContainerInterface;
use Slim\App;
use Slim\Interfaces\RouteCollectorProxyInterface as Group;

final class Routes
{
    public const OUTBOX_SCOPE = 'outbox:read';

    /**
     * @param App<ContainerInterface|null> $app
     */
    public static function register(App $app, Container $c): void
    {
        $app->get('/health', $c->healthController());

        $app->group('/api/v1', function (Group $v1) use ($c): void {
            $auth = $c->authController();
            $v1->post('/auth/login', [$auth, 'login']);
            $v1->post('/auth/refresh', [$auth, 'refresh']);
            $v1->post('/auth/logout', [$auth, 'logout']);

            $v1->group('', function (Group $g) use ($c, $auth): void {
                $g->get('/me', [$auth, 'me']);

                $g->post('/users', [$c->userController(), 'create']);

                $departments = $c->departmentController();
                $g->get('/departments', [$departments, 'list']);
                $g->post('/departments', [$departments, 'create']);
                $g->get('/departments/{id:[0-9]+}', [$departments, 'get']);
                $g->patch('/departments/{id:[0-9]+}', [$departments, 'update']);

                $employees = $c->employeeController();
                $g->get('/employees', [$employees, 'list']);
                $g->post('/employees', [$employees, 'create']);
                $g->get('/employees/{id:[0-9]+}', [$employees, 'get']);
                $g->patch('/employees/{id:[0-9]+}', [$employees, 'update']);

                $attendance = $c->attendanceController();
                $g->get('/attendance', [$attendance, 'list']);
                $g->post('/attendance', [$attendance, 'create']);
                $g->post('/attendance/import', [$attendance, 'import']);
                $g->post('/attendance/clock-in', [$attendance, 'clockIn']);
                $g->post('/attendance/clock-out', [$attendance, 'clockOut']);
                $g->get('/attendance/{id:[0-9]+}', [$attendance, 'get']);
                $g->put('/attendance/{id:[0-9]+}', [$attendance, 'update']);
                $g->delete('/attendance/{id:[0-9]+}', [$attendance, 'delete']);

                $overtime = $c->overtimeController();
                $g->get('/overtime-requests', [$overtime, 'list']);
                $g->post('/overtime-requests', [$overtime, 'submit']);
                $g->get('/overtime-requests/{id:[0-9]+}', [$overtime, 'get']);
                $g->post('/overtime-requests/{id:[0-9]+}/approve', [$overtime, 'approve']);
                $g->post('/overtime-requests/{id:[0-9]+}/reject', [$overtime, 'reject']);

                $payroll = $c->payrollController();
                $g->get('/payroll-periods', [$payroll, 'list']);
                $g->post('/payroll-periods', [$payroll, 'create']);
                $g->get('/payroll-periods/{id:[0-9]+}', [$payroll, 'get']);
                $g->post('/payroll-periods/{id:[0-9]+}/calculate', [$payroll, 'calculate']);
                $g->post('/payroll-periods/{id:[0-9]+}/approve', [$payroll, 'approve']);
                $g->post('/payroll-periods/{id:[0-9]+}/mark-paid', [$payroll, 'markPaid']);
                $g->get('/payroll-periods/{id:[0-9]+}/payslips', [$payroll, 'payslips']);

                $payslips = $c->payslipController();
                $g->get('/payslips', [$payslips, 'list']);
                $g->get('/payslips/{id:[0-9]+}', [$payslips, 'get']);

                $settings = $c->settingsController();
                $g->get('/holidays', [$settings, 'holidays']);
                $g->post('/holidays', [$settings, 'addHoliday']);
                $g->delete('/holidays/{id:[0-9]+}', [$settings, 'deleteHoliday']);
                $g->get('/bpjs-settings', [$settings, 'bpjs']);
                $g->post('/bpjs-settings', [$settings, 'addBpjs']);

                $g->get('/audit-logs', [$c->auditController(), 'list']);
            })->add(new AuthMiddleware($c->tokenIssuer()));

            $v1->group('/integrations', function (Group $g) use ($c): void {
                $integration = $c->integrationController();
                $g->get('/outbox', [$integration, 'outbox']);
                $g->post('/outbox/ack', [$integration, 'ack']);
            })->add(new ApiKeyMiddleware($c->apiKeyAuthenticator(), self::OUTBOX_SCOPE));
        });
    }
}
