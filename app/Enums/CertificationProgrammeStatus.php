<?php

namespace App\Enums;

enum CertificationProgrammeStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
    case Unpublished = 'unpublished';
    case Archived = 'archived';

    public function isCatalogueVisible(): bool
    {
        return $this === self::Published;
    }

    public function allowsArchive(): bool
    {
        return $this === self::Unpublished;
    }

    public function allowsUnarchive(): bool
    {
        return $this === self::Archived;
    }
}
