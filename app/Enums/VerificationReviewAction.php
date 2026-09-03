<?php

namespace App\Enums;

enum VerificationReviewAction: string
{
    case Submitted = 'submitted';
    case Resubmitted = 'resubmitted';
    case StartedReview = 'started_review';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case RequestedInformation = 'requested_information';
}
