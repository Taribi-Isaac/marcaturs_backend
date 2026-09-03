<?php

namespace App\Enums;

enum VerificationRequirementType: string
{
    case Text = 'text';
    case Document = 'document';
    case Email = 'email';
    case Phone = 'phone';
    case Other = 'other';
}
