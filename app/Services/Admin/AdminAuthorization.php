<?php

namespace App\Services\Admin;

use App\Enums\AdminPermission;
use App\Enums\AdminStaffRole;
use App\Models\AdminStaffProfile;
use App\Models\User;
use App\Support\Admin\AdminPermissionMatrix;
use Illuminate\Auth\Access\AuthorizationException;

final class AdminAuthorization
{
    public function allows(User $user, AdminPermission $permission): bool
    {
        if (! $user->isAdmin()) {
            return false;
        }

        $profile = $this->profile($user);
        if ($profile === null) {
            return false;
        }

        return AdminPermissionMatrix::roleAllows($profile->staff_role, $permission);
    }

    public function assert(User $user, AdminPermission $permission): void
    {
        if (! $this->allows($user, $permission)) {
            throw new AuthorizationException('You are not authorized to perform this action.');
        }
    }

    public function staffRole(User $user): ?AdminStaffRole
    {
        return $this->profile($user)?->staff_role;
    }

    /**
     * @return list<string>
     */
    public function permissionValues(User $user): array
    {
        $role = $this->staffRole($user);
        if ($role === null) {
            return [];
        }

        return AdminPermissionMatrix::valuesForRole($role);
    }

    public function profile(User $user): ?AdminStaffProfile
    {
        if ($user->relationLoaded('adminStaffProfile')) {
            return $user->adminStaffProfile;
        }

        return $user->adminStaffProfile()->first();
    }
}
