<?php

namespace App\Enums;

enum CommissionTrigger: string
{
    case PaymentConfirmation = 'payment_confirmation';
    case Other = 'other';
}
