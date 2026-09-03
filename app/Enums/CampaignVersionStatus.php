<?php

namespace App\Enums;

enum CampaignVersionStatus: string
{
    case Draft = 'draft';
    case Published = 'published';

    public function isMutable(): bool
    {
        return $this === self::Draft;
    }
}
