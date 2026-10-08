<?php

declare(strict_types=1);

namespace HrPayroll\Domain\Payroll;

enum BpjsProgram: string
{
    case Kesehatan = 'kesehatan';
    case Jht = 'jht';
    case Jp = 'jp';
    case Jkk = 'jkk';
    case Jkm = 'jkm';

    public function label(): string
    {
        return match ($this) {
            self::Kesehatan => 'BPJS Kesehatan',
            self::Jht => 'BPJS Ketenagakerjaan JHT',
            self::Jp => 'BPJS Ketenagakerjaan JP',
            self::Jkk => 'BPJS Ketenagakerjaan JKK',
            self::Jkm => 'BPJS Ketenagakerjaan JKM',
        };
    }
}
