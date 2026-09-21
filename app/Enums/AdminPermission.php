<?php

namespace App\Enums;

enum AdminPermission: string
{
    case OverviewView = 'overview.view';
    case UsersView = 'users.view';
    case UsersManage = 'users.manage';
    case CampaignsView = 'campaigns.view';
    case CampaignsManage = 'campaigns.manage';
    case VerificationView = 'verification.view';
    case VerificationReview = 'verification.review';
    case VerificationConfigure = 'verification.configure';
    case DealsView = 'deals.view';
    case DisputesView = 'disputes.view';
    case DisputesManage = 'disputes.manage';
    case ConfigurationManage = 'configuration.manage';
    case ConversationsModerate = 'conversations.moderate';
    case StaffView = 'staff.view';
    case StaffManage = 'staff.manage';
    case CertificationView = 'certification.view';
    case CertificationManage = 'certification.manage';
    case CertificationLearnersView = 'certification.learners.view';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
