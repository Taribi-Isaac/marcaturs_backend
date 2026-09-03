<?php

namespace App\Enums;

enum CampaignStatus: string
{
    case Draft = 'draft';
    case Submitted = 'submitted';
    case Approved = 'approved';
    case Active = 'active';
    case Expiring = 'expiring';
    case Expired = 'expired';
    case Closed = 'closed';
    case Deactivated = 'deactivated';
    case Suspended = 'suspended';

    public function allowsBusinessMetadataEdit(): bool
    {
        return $this === self::Draft;
    }

    public function allowsVersionMutation(): bool
    {
        return in_array($this, [
            self::Draft,
            self::Active,
            self::Expiring,
            self::Deactivated,
            self::Expired,
        ], true);
    }

    public function allowsNewDeals(): bool
    {
        return in_array($this, [self::Active, self::Expiring], true);
    }
}
