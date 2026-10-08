<?php

declare(strict_types=1);

namespace HrPayroll\Domain;

enum OvertimeStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
}
