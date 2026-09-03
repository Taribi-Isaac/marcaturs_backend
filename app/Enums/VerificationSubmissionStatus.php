<?php

namespace App\Enums;

enum VerificationSubmissionStatus: string
{
    case Pending = 'pending';
    case UnderReview = 'under_review';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case MoreInformationRequired = 'more_information_required';

    public function allowsResubmission(): bool
    {
        return $this === self::Rejected || $this === self::MoreInformationRequired;
    }

    public function allowsReview(): bool
    {
        return $this === self::Pending || $this === self::UnderReview;
    }
}
