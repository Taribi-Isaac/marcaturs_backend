<?php

namespace App\Enums;

enum PaymentEvidenceStatus: string
{
    case Submitted = 'submitted';
    case Rejected = 'rejected';
}
