<?php

return [

    /*
     | Source of Truth §5.2 / PRD §8.24: listing duration is administrator-configurable.
     | 30 is the documented example, not a legal constant.
     */
    'free_listing_days' => (int) env('CAMPAIGN_FREE_LISTING_DAYS', 30),

    /*
     | Days before listing_expires_at when ACTIVE becomes EXPIRING.
     | 0 means the processor skips EXPIRING and moves ACTIVE → EXPIRED at expiry.
     | No lead window is defined in the foundational documents, so the default is 0.
     */
    'expiring_lead_days' => (int) env('CAMPAIGN_EXPIRING_LEAD_DAYS', 0),

    /*
     | Campaign marketing files and Campaign Cover images (TAD §18).
     | Disk may later point at private S3.
     | Size is platform configuration, not a product-document constant.
     | Cover reuses CAMPAIGN_RESOURCE_MAX_FILE_KB (no separate cover limit).
     */
    'media_disk' => env('CAMPAIGN_MEDIA_DISK', 'campaign_media'),

    'max_resource_kilobytes' => (int) env('CAMPAIGN_RESOURCE_MAX_FILE_KB', 20480),

];
