<?php

namespace Tests\Unit\Admin;

use App\Enums\AdminPermission;
use App\Enums\AdminStaffRole;
use App\Support\Admin\AdminPermissionMatrix;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AdminPermissionMatrixTest extends TestCase
{
    public function test_super_admin_has_all_permissions(): void
    {
        $this->assertSame(
            AdminPermission::cases(),
            AdminPermissionMatrix::forRole(AdminStaffRole::SuperAdmin),
        );
    }

    #[DataProvider('rolePermissionProvider')]
    public function test_role_allows_expected_permissions(AdminStaffRole $role, AdminPermission $permission, bool $allowed): void
    {
        $this->assertSame($allowed, AdminPermissionMatrix::roleAllows($role, $permission));
    }

    /**
     * @return array<string, array{0: AdminStaffRole, 1: AdminPermission, 2: bool}>
     */
    public static function rolePermissionProvider(): array
    {
        return [
            'ops users manage' => [AdminStaffRole::Operations, AdminPermission::UsersManage, true],
            'ops staff manage denied' => [AdminStaffRole::Operations, AdminPermission::StaffManage, false],
            'verification review' => [AdminStaffRole::Verification, AdminPermission::VerificationReview, true],
            'verification disputes denied' => [AdminStaffRole::Verification, AdminPermission::DisputesManage, false],
            'verification campaigns denied' => [AdminStaffRole::Verification, AdminPermission::CampaignsManage, false],
            'moderation campaigns' => [AdminStaffRole::Moderation, AdminPermission::CampaignsManage, true],
            'moderation users denied' => [AdminStaffRole::Moderation, AdminPermission::UsersManage, false],
            'moderation disputes manage denied' => [AdminStaffRole::Moderation, AdminPermission::DisputesManage, false],
            'moderation disputes view' => [AdminStaffRole::Moderation, AdminPermission::DisputesView, true],
            'moderation config denied' => [AdminStaffRole::Moderation, AdminPermission::ConfigurationManage, false],
            'ops certification manage' => [AdminStaffRole::Operations, AdminPermission::CertificationManage, true],
            'ops certification view' => [AdminStaffRole::Operations, AdminPermission::CertificationView, true],
            'ops certification learners' => [AdminStaffRole::Operations, AdminPermission::CertificationLearnersView, true],
            'verification certification denied' => [AdminStaffRole::Verification, AdminPermission::CertificationManage, false],
            'moderation certification denied' => [AdminStaffRole::Moderation, AdminPermission::CertificationView, false],
        ];
    }
}
