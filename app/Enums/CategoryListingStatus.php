<?php

namespace App\Enums;

enum CategoryListingStatus: string
{
    case Allowed = 'allowed';
    case Restricted = 'restricted';
    case Prohibited = 'prohibited';

    public function mayBeAssignedToCampaigns(): bool
    {
        return $this !== self::Prohibited;
    }
}
