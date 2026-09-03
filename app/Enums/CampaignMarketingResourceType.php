<?php

namespace App\Enums;

enum CampaignMarketingResourceType: string
{
    case Image = 'image';
    case Flyer = 'flyer';
    case Video = 'video';
    case Brochure = 'brochure';
    case Document = 'document';

    /**
     * @return list<string>
     */
    public function allowedMimes(): array
    {
        return match ($this) {
            self::Image => ['jpeg', 'jpg', 'png', 'webp'],
            self::Flyer => ['jpeg', 'jpg', 'png', 'webp', 'pdf'],
            self::Video => ['mp4', 'webm'],
            self::Brochure, self::Document => ['pdf'],
        };
    }
}
