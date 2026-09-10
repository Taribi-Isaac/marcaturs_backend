<?php

namespace App\Support\Campaigns;

use App\Models\Campaign;
use App\Models\CampaignCover;

/**
 * Safe Campaign Cover presentation for API resources.
 * Never exposes disk, path, or other private storage metadata.
 */
final class CampaignCoverPresentation
{
    /**
     * Owner / Admin metadata. URL points at the authenticated download stream.
     *
     * @return array{available: bool, url: string|null, mime_type?: string, size_bytes?: int, original_filename?: string}
     */
    public static function managed(Campaign $campaign, string $downloadUrl): array
    {
        $cover = self::resolveCover($campaign);

        if ($cover === null) {
            return [
                'available' => false,
                'url' => null,
            ];
        }

        return [
            'available' => true,
            'url' => $downloadUrl,
            'mime_type' => $cover->mime_type,
            'size_bytes' => $cover->size_bytes,
            'original_filename' => $cover->original_filename,
        ];
    }

    /**
     * Marketplace surfaces: availability + controlled public stream URL only.
     *
     * @return array{available: bool, url: string|null}
     */
    public static function marketplace(Campaign $campaign): array
    {
        $cover = self::resolveCover($campaign);

        if ($cover === null) {
            return [
                'available' => false,
                'url' => null,
            ];
        }

        return [
            'available' => true,
            'url' => self::publicUrl($campaign->id),
        ];
    }

    public static function publicUrl(int $campaignId): string
    {
        return url('/api/v1/marketplace/campaigns/'.$campaignId.'/cover');
    }

    public static function ownerDownloadUrl(int $campaignId): string
    {
        return url('/api/v1/campaigns/'.$campaignId.'/cover/download');
    }

    public static function adminDownloadUrl(int $campaignId): string
    {
        return url('/api/v1/admin/campaigns/'.$campaignId.'/cover/download');
    }

    private static function resolveCover(Campaign $campaign): ?CampaignCover
    {
        if ($campaign->relationLoaded('cover')) {
            return $campaign->cover;
        }

        return $campaign->cover()->first();
    }
}
