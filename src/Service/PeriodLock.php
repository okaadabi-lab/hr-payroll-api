<?php

declare(strict_types=1);

namespace HrPayroll\Service;

use HrPayroll\Auth\Principal;
use HrPayroll\Domain\Exception\Conflict;
use HrPayroll\Domain\PeriodStatus;
use HrPayroll\Repository\PayrollPeriodRepository;

/**
 * Guards writes to payroll inputs (attendance, overtime, holidays).
 *
 * Must be called inside the writer's transaction. It locks the period row with
 * SELECT ... FOR UPDATE, so a write and the approval of the same period are
 * serialised: either the write commits first and approval sees it, or approval
 * commits first and the write gets 409.
 */
final class PeriodLock
{
    public function __construct(
        private readonly PayrollPeriodRepository $periods,
        private readonly AuditLogger $audit,
    ) {
    }

    /**
     * For writes that do not change what payroll pays, such as submitting or
     * rejecting an overtime request.
     */
    public function assertNotLocked(string $date): void
    {
        $period = $this->periods->findByMonthForUpdate(substr($date, 0, 7));
        if ($period !== null && PeriodStatus::from($period['status'])->isLocked()) {
            throw self::lockedError(substr($date, 0, 7), $period['status']);
        }
    }

    /**
     * For writes that change payroll inputs. A period that was already
     * calculated goes back to draft, so stale figures can never be approved.
     */
    public function assertWritable(Principal $actor, string $date): void
    {
        $month = substr($date, 0, 7);
        $period = $this->periods->findByMonthForUpdate($month);
        if ($period === null) {
            return;
        }
        $status = PeriodStatus::from($period['status']);
        if ($status->isLocked()) {
            throw self::lockedError($month, $period['status']);
        }
        if ($status === PeriodStatus::Calculated) {
            $this->invalidate($actor, $period['id'], 'attendance, overtime or holiday changed');
        }
    }

    /**
     * Employee master data is read by every open period, so a change resets
     * all calculated periods.
     */
    public function invalidateAllCalculated(Principal $actor, string $reason): void
    {
        $this->invalidateCalculatedFrom($actor, '0000-01', $reason);
    }

    /**
     * New employees and BPJS settings affect periods from a given month on.
     */
    public function invalidateCalculatedFrom(Principal $actor, string $fromMonth, string $reason): void
    {
        foreach ($this->periods->calculatedFrom($fromMonth) as $periodId) {
            $this->invalidate($actor, $periodId, $reason);
        }
    }

    private function invalidate(Principal $actor, int $periodId, string $reason): void
    {
        $this->periods->resetToDraft($periodId);
        $this->audit->record(
            $actor,
            'payroll.invalidated',
            'payroll_period',
            $periodId,
            ['status' => PeriodStatus::Calculated->value],
            ['status' => PeriodStatus::Draft->value, 'reason' => $reason],
        );
    }

    public function assertNoLockedPeriodFrom(string $fromMonth): void
    {
        $locked = $this->periods->lockedFrom($fromMonth);
        if ($locked !== null) {
            throw new Conflict(
                "Payroll period {$locked} is already approved or paid; the change would alter it.",
                'period_locked',
            );
        }
    }

    public function isLocked(string $date): bool
    {
        $period = $this->periods->findByMonthForUpdate(substr($date, 0, 7));

        return $period !== null && PeriodStatus::from($period['status'])->isLocked();
    }

    private static function lockedError(string $month, string $status): Conflict
    {
        return new Conflict("Payroll period {$month} is {$status} and can no longer be changed.", 'period_locked');
    }
}
