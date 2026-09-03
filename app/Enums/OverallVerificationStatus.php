<?php

namespace App\Enums;

enum OverallVerificationStatus: string
{
    case NotStarted = 'NOT_STARTED';
    case Pending = 'PENDING';
    case UnderReview = 'UNDER_REVIEW';
    case Verified = 'VERIFIED';
    case Rejected = 'REJECTED';
    case MoreInformationRequired = 'MORE_INFORMATION_REQUIRED';
}
