<?php

namespace App\Enums;

enum CertificationResourceType: string
{
    case Video = 'video';
    case Text = 'text';
    case Downloadable = 'downloadable';
    case ExternalReference = 'external_reference';

    /**
     * @return list<string>
     */
    public function allowedMimes(): array
    {
        return match ($this) {
            self::Downloadable => ['pdf', 'doc', 'docx', 'ppt', 'pptx', 'xls', 'xlsx', 'txt', 'png', 'jpg', 'jpeg', 'webp', 'zip'],
            default => [],
        };
    }

    public function requiresFile(): bool
    {
        return $this === self::Downloadable;
    }

    public function requiresExternalUrl(): bool
    {
        return in_array($this, [self::Video, self::ExternalReference], true);
    }

    public function requiresBodyText(): bool
    {
        return $this === self::Text;
    }
}
