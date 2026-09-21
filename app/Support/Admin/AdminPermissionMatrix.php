<?php

namespace App\Support\Admin;

use App\Enums\AdminPermission;
use App\Enums\AdminStaffRole;

/**
 * Code-defined Admin staff role → permission matrix (MH-BE-045 / MH-DECISION-001).
 * No per-user overrides and no database ACL.
 */
final class AdminPermissionMatrix
{
    /**
     * @return list<AdminPermission>
     */
    public static function forRole(AdminStaffRole $role): array
    {
        return match ($role) {
            AdminStaffRole::SuperAdmin => AdminPermission::cases(),
            AdminStaffRole::Operations => [
                AdminPermission::OverviewView,
                AdminPermission::UsersView,
                AdminPermission::UsersManage,
                AdminPermission::CampaignsView,
                AdminPermission::CampaignsManage,
                AdminPermission::VerificationView,
                AdminPermission::VerificationReview,
                AdminPermission::VerificationConfigure,
                AdminPermission::DealsView,
                AdminPermission::DisputesView,
                AdminPermission::DisputesManage,
                AdminPermission::ConfigurationManage,
                AdminPermission::ConversationsModerate,
                AdminPermission::CertificationView,
                AdminPermission::CertificationManage,
                AdminPermission::CertificationLearnersView,
            ],
            AdminStaffRole::Verification => [
                AdminPermission::OverviewView,
                AdminPermission::VerificationView,
                AdminPermission::VerificationReview,
            ],
            AdminStaffRole::Moderation => [
                AdminPermission::OverviewView,
                AdminPermission::CampaignsView,
                AdminPermission::CampaignsManage,
                AdminPermission::DisputesView,
                AdminPermission::ConversationsModerate,
            ],
        };
    }

    /**
     * @return list<string>
     */
    public static function valuesForRole(AdminStaffRole $role): array
    {
        return array_map(
            static fn (AdminPermission $permission) => $permission->value,
            self::forRole($role),
        );
    }

    public static function roleAllows(AdminStaffRole $role, AdminPermission $permission): bool
    {
        return in_array($permission, self::forRole($role), true);
    }
}
