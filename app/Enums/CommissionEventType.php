<?php

namespace App\Enums;

enum CommissionEventType: string
{
    case Paid = 'commission_paid';

    case Received = 'commission_received';

    case Overdue = 'commission_overdue';
}
