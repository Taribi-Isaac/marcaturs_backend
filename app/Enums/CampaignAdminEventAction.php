<?php

namespace App\Enums;

enum CampaignAdminEventAction: string
{
    case Approved = 'approved';
    case Rejected = 'rejected';
    case ModificationRequested = 'modification_requested';
    case Activated = 'activated';
    case Suspended = 'suspended';
    case Closed = 'closed';
}
