<?php

declare(strict_types=1);

namespace HrPayroll\Domain;

enum AttendanceStatus: string
{
    case Present = 'present';
    case Leave = 'leave';
    case Sick = 'sick';
    case Absent = 'absent';
}
