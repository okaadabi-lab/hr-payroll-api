<?php

declare(strict_types=1);

namespace HrPayroll\Http\Controller;

use HrPayroll\Domain\Exception\ValidationFailed;
use HrPayroll\Domain\Payroll\BpjsProgram;
use HrPayroll\Http\Input;
use HrPayroll\Http\Json;
use HrPayroll\Service\SettingsService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class SettingsController extends Controller
{
    public function __construct(private readonly SettingsService $settings)
    {
    }

    public function holidays(Request $request, Response $response): Response
    {
        self::principal($request);
        $year = $request->getQueryParams()['year'] ?? null;
        if ($year !== null && (!is_string($year) || preg_match('/^\d{4}$/', $year) !== 1)) {
            throw ValidationFailed::single('year', 'Must be a four-digit year.');
        }

        return Json::response($response, ['data' => $this->settings->holidays($year === null ? null : (int) $year)]);
    }

    public function addHoliday(Request $request, Response $response): Response
    {
        $input = new Input(Json::body($request));
        $date = $input->date('holiday_date');
        $name = $input->string('name', 150);
        $input->validate();

        $holiday = $this->settings->addHoliday(self::principal($request), (string) $date, (string) $name);

        return Json::response($response, ['data' => $holiday], 201);
    }

    /**
     * @param array<string, string> $args
     */
    public function deleteHoliday(Request $request, Response $response, array $args): Response
    {
        $this->settings->deleteHoliday(self::principal($request), self::id($args));

        return $response->withStatus(204);
    }

    public function bpjs(Request $request, Response $response): Response
    {
        return Json::response($response, ['data' => $this->settings->bpjsSettings(self::principal($request))]);
    }

    public function addBpjs(Request $request, Response $response): Response
    {
        $input = new Input(Json::body($request));
        $program = $input->oneOf('program', array_column(BpjsProgram::cases(), 'value'));
        $employeeBp = $input->int('employee_rate_bp', 0, 10_000);
        $employerBp = $input->int('employer_rate_bp', 0, 10_000);
        $wageCap = $input->isNull('wage_cap') ? null : $input->int('wage_cap', 1, Input::MAX_MONEY, false);
        $effectiveFrom = $input->date('effective_from');
        if (is_string($effectiveFrom) && !str_ends_with($effectiveFrom, '-01')) {
            $input->addError('effective_from', 'Must be the first day of a month.');
        }
        $input->validate();

        $setting = $this->settings->addBpjsSetting(
            self::principal($request),
            (string) $program,
            (int) $employeeBp,
            (int) $employerBp,
            $wageCap,
            (string) $effectiveFrom,
        );

        return Json::response($response, ['data' => $setting], 201);
    }
}
