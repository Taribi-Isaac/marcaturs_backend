<?php

namespace App\Enums;

enum CertificationProgrammeVersionStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
    case Unpublished = 'unpublished';

    public function isMutable(): bool
    {
        return $this === self::Draft;
    }

    public function isPublished(): bool
    {
        return $this === self::Published;
    }
}
