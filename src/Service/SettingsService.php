<?php

declare(strict_types=1);

namespace HrPayroll\Service;

use HrPayroll\Auth\Principal;
use HrPayroll\Domain\Exception\Conflict;
use HrPayroll\Domain\Exception\NotFound;
use HrPayroll\Domain\Role;
use HrPayroll\Infrastructure\Database;
use HrPayroll\Repository\BpjsSettingRepository;
use HrPayroll\Repository\HolidayRepository;

/**
 * Reference data that feeds the calculator: public holidays and BPJS rates.
 */
final class SettingsService
{
    public function __construct(
        private readonly Database $db,
        private readonly HolidayRepository $holidays,
        private readonly BpjsSettingRepository $bpjs,
        private readonly PeriodLock $periodLock,
        private readonly AuditLogger $audit,
    ) {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function holidays(?int $year): array
    {
        return $this->holidays->listForYear($year);
    }

    /**
     * @return array<string, mixed>
     */
    public function addHoliday(Principal $actor, string $date, string $name): array
    {
        $actor->requireRole(Role::Admin, Role::Hr);

        return $this->db->transactional(function () use ($actor, $date, $name): array {
            $this->periodLock->assertWritable($actor, $date);
            if ($this->holidays->existsOn($date)) {
                throw new Conflict('A holiday on this date already exists.', 'duplicate_holiday');
            }
            $id = $this->holidays->create($date, $name);
            $after = ['holiday_date' => $date, 'name' => $name];
            $this->audit->record($actor, 'holiday.created', 'holiday', $id, null, $after);

            return $this->holidays->find($id) ?? [];
        });
    }

    public function deleteHoliday(Principal $actor, int $id): void
    {
        $actor->requireRole(Role::Admin, Role::Hr);

        $this->db->transactional(function () use ($actor, $id): void {
            $holiday = $this->holidays->find($id) ?? throw new NotFound('Holiday not found.');
            $this->periodLock->assertWritable($actor, (string) $holiday['holiday_date']);
            $this->holidays->delete($id);
            $this->audit->record($actor, 'holiday.deleted', 'holiday', $id, $holiday, null);
        });
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function bpjsSettings(Principal $actor): array
    {
        $actor->requireRole(Role::Admin, Role::Hr);

        return $this->bpjs->all();
    }

    /**
     * Settings are append-only: a new row with a later effective_from replaces
     * the old one for periods from that month, keeping history reproducible.
     *
     * @return array<string, mixed>
     */
    public function addBpjsSetting(
        Principal $actor,
        string $program,
        int $employeeBp,
        int $employerBp,
        ?int $wageCap,
        string $effectiveFrom,
    ): array {
        $actor->requireRole(Role::Admin);

        $work = function () use ($actor, $program, $employeeBp, $employerBp, $wageCap, $effectiveFrom): array {
            if ($this->bpjs->existsFor($program, $effectiveFrom)) {
                throw new Conflict('A setting for this program and date already exists.', 'duplicate_setting');
            }
            $this->periodLock->assertNoLockedPeriodFrom(substr($effectiveFrom, 0, 7));
            $id = $this->bpjs->create($program, $employeeBp, $employerBp, $wageCap, $effectiveFrom);
            $this->periodLock->invalidateCalculatedFrom($actor, substr($effectiveFrom, 0, 7), 'BPJS setting added');
            $this->audit->record($actor, 'bpjs_setting.created', 'bpjs_setting', $id, null, [
                'program' => $program,
                'employee_rate_bp' => $employeeBp,
                'employer_rate_bp' => $employerBp,
                'wage_cap' => $wageCap,
                'effective_from' => $effectiveFrom,
            ]);

            return $this->bpjs->find($id) ?? [];
        };

        return $this->db->transactional($work);
    }
}
