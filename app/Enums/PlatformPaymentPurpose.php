<?php

namespace App\Enums;

enum PlatformPaymentPurpose: string
{
    case CampaignExtension = 'campaign_extension';

    case CampaignFeatured = 'campaign_featured';

    case CertificationEnrollment = 'certification_enrollment';
}
