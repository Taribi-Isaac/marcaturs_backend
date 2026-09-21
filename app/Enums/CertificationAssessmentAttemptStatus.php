<?php

namespace App\Enums;

enum CertificationAssessmentAttemptStatus: string
{
    case InProgress = 'in_progress';
    case Submitted = 'submitted';
}
