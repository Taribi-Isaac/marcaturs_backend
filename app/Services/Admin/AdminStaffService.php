<?php

namespace App\Services\Admin;

use App\Enums\AccountStatus;
use App\Enums\AdminPermission;
use App\Enums\AdminStaffEventAction;
use App\Enums\AdminStaffRole;
use App\Enums\Role;
use App\Enums\UserStatusAction;
use App\Models\AdminStaffEvent;
use App\Models\AdminStaffInvitation;
use App\Models\AdminStaffProfile;
use App\Models\User;
use App\Models\UserStatusEvent;
use App\Notifications\Admin\AdminStaffInvitationNotification;
use App\Support\Api\ApiErrorCode;
use App\Support\Api\ApiResponse;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AdminStaffService
{
    public function __construct(
        private readonly AdminAuthorization $authorization,
    ) {}

    /**
     * @return LengthAwarePaginator<int, User>
     */
    public function list(User $actor, ?AdminStaffRole $role, ?AccountStatus $status, ?string $search, int $perPage): LengthAwarePaginator
    {
        $this->authorization->assert($actor, AdminPermission::StaffView);

        $query = $this->staffQuery()
            ->with(['adminStaffProfile'])
            ->orderByDesc('id');

        if ($role !== null) {
            $query->whereHas('adminStaffProfile', fn (Builder $builder) => $builder->where('staff_role', $role));
        }

        if ($status !== null) {
            $query->where('status', $status);
        }

        if ($search !== null && trim($search) !== '') {
            $term = '%'.str_replace(['%', '_'], ['\\%', '\\_'], trim($search)).'%';
            $query->where(function (Builder $builder) use ($term): void {
                $builder->where('name', 'like', $term)
                    ->orWhere('email', 'like', $term);
            });
        }

        return $query->paginate($perPage);
    }

    public function show(User $actor, int $userId): User
    {
        $this->authorization->assert($actor, AdminPermission::StaffView);

        $user = $this->findStaffOrFail($userId);
        $user->load([
            'adminStaffProfile.createdBy:id,name,email',
            'adminStaffEvents' => fn ($query) => $query->orderByDesc('id')->limit(50),
            'adminStaffEvents.actor:id,name,email',
        ]);

        return $user;
    }

    /**
     * @return array{invitation: AdminStaffInvitation, plain_token: string}
     */
    public function invite(User $actor, string $name, string $email, AdminStaffRole $staffRole): array
    {
        $this->authorization->assert($actor, AdminPermission::StaffManage);

        $email = strtolower(trim($email));
        $name = trim($name);

        return DB::transaction(function () use ($actor, $name, $email, $staffRole): array {
            $existing = User::query()->where('email', $email)->lockForUpdate()->first();
            if ($existing !== null) {
                throw ValidationException::withMessages([
                    'email' => ['An account with this email already exists.'],
                ]);
            }

            $pending = AdminStaffInvitation::query()
                ->where('email', $email)
                ->whereNull('accepted_at')
                ->whereNull('revoked_at')
                ->where('expires_at', '>', now())
                ->lockForUpdate()
                ->exists();

            if ($pending) {
                throw ValidationException::withMessages([
                    'email' => ['A pending invitation already exists for this email.'],
                ]);
            }

            $plainToken = Str::random(64);
            $invitation = new AdminStaffInvitation;
            $invitation->email = $email;
            $invitation->name = $name;
            $invitation->staff_role = $staffRole;
            $invitation->token_hash = hash('sha256', $plainToken);
            $invitation->invited_by_user_id = $actor->id;
            $invitation->expires_at = now()->addHours((int) config('admin_staff.invitation_ttl_hours', 72));
            $invitation->save();

            $this->recordEvent(
                actor: $actor,
                action: AdminStaffEventAction::Invited,
                targetUserId: null,
                invitationId: $invitation->id,
                newStaffRole: $staffRole,
                meta: ['email' => $email, 'name' => $name],
            );

            return ['invitation' => $invitation->fresh() ?? $invitation, 'plain_token' => $plainToken];
        });
    }

    public function revokeInvitation(User $actor, int $invitationId): AdminStaffInvitation
    {
        $this->authorization->assert($actor, AdminPermission::StaffManage);

        return DB::transaction(function () use ($actor, $invitationId): AdminStaffInvitation {
            $invitation = AdminStaffInvitation::query()->lockForUpdate()->find($invitationId);
            if ($invitation === null) {
                throw (new ModelNotFoundException)->setModel(AdminStaffInvitation::class, [$invitationId]);
            }

            if ($invitation->accepted_at !== null) {
                throw new HttpResponseException(ApiResponse::error(
                    ApiErrorCode::BUSINESS_VALIDATION,
                    'This invitation has already been accepted.',
                    422,
                ));
            }

            if ($invitation->revoked_at !== null) {
                throw new HttpResponseException(ApiResponse::error(
                    ApiErrorCode::BUSINESS_VALIDATION,
                    'This invitation has already been revoked.',
                    422,
                ));
            }

            $invitation->revoked_at = now();
            $invitation->save();

            $this->recordEvent(
                actor: $actor,
                action: AdminStaffEventAction::InvitationRevoked,
                targetUserId: null,
                invitationId: $invitation->id,
                previousStaffRole: $invitation->staff_role,
                meta: ['email' => $invitation->email],
            );

            return $invitation->fresh() ?? $invitation;
        });
    }

    public function acceptInvitation(string $plainToken, string $password): User
    {
        $tokenHash = hash('sha256', $plainToken);

        return DB::transaction(function () use ($tokenHash, $password): User {
            $invitation = AdminStaffInvitation::query()
                ->where('token_hash', $tokenHash)
                ->lockForUpdate()
                ->first();

            if ($invitation === null) {
                throw new HttpResponseException(ApiResponse::error(
                    ApiErrorCode::BUSINESS_VALIDATION,
                    'This invitation is invalid or no longer available.',
                    422,
                ));
            }

            if ($invitation->accepted_at !== null || $invitation->revoked_at !== null || $invitation->expires_at->isPast()) {
                throw new HttpResponseException(ApiResponse::error(
                    ApiErrorCode::BUSINESS_VALIDATION,
                    'This invitation is invalid or no longer available.',
                    422,
                ));
            }

            $existing = User::query()->where('email', $invitation->email)->lockForUpdate()->first();
            if ($existing !== null) {
                throw new HttpResponseException(ApiResponse::error(
                    ApiErrorCode::BUSINESS_VALIDATION,
                    'This invitation is invalid or no longer available.',
                    422,
                ));
            }

            $user = new User;
            $user->name = $invitation->name;
            $user->email = $invitation->email;
            $user->password = $password;
            $user->role = Role::Admin;
            $user->status = AccountStatus::Active;
            $user->email_verified_at = now();
            $user->save();

            $profile = new AdminStaffProfile;
            $profile->user_id = $user->id;
            $profile->staff_role = $invitation->staff_role;
            $profile->created_by_user_id = $invitation->invited_by_user_id;
            $profile->save();

            $invitation->accepted_at = now();
            $invitation->save();

            $actor = User::query()->find($invitation->invited_by_user_id) ?? $user;

            $this->recordEvent(
                actor: $actor,
                action: AdminStaffEventAction::InvitationAccepted,
                targetUserId: $user->id,
                invitationId: $invitation->id,
                newStaffRole: $invitation->staff_role,
                newStatus: AccountStatus::Active,
                meta: ['email' => $user->email],
            );

            return $user->fresh(['adminStaffProfile']) ?? $user;
        });
    }

    public function changeRole(User $actor, int $targetId, AdminStaffRole $newRole): User
    {
        $this->authorization->assert($actor, AdminPermission::StaffManage);

        return DB::transaction(function () use ($actor, $targetId, $newRole): User {
            $target = $this->lockStaffOrFail($targetId);
            $profile = $target->adminStaffProfile;
            if ($profile === null) {
                throw (new ModelNotFoundException)->setModel(User::class, [$targetId]);
            }

            $profile = AdminStaffProfile::query()->where('user_id', $target->id)->lockForUpdate()->firstOrFail();
            $previousRole = $profile->staff_role;

            if ($actor->id === $target->id) {
                // Self-demotion is never allowed; last-Super-Admin rules still apply if this ever changes.
                throw new AuthorizationException('You cannot change your own staff role.');
            }

            if ($previousRole === $newRole) {
                return $target->fresh(['adminStaffProfile']) ?? $target;
            }

            if ($previousRole === AdminStaffRole::SuperAdmin && $newRole !== AdminStaffRole::SuperAdmin) {
                $this->assertNotLastEffectiveSuperAdmin($target->id);
            }

            $profile->staff_role = $newRole;
            $profile->save();

            $this->recordEvent(
                actor: $actor,
                action: AdminStaffEventAction::RoleChanged,
                targetUserId: $target->id,
                previousStaffRole: $previousRole,
                newStaffRole: $newRole,
            );

            $this->revokeAccess($target);

            return $target->fresh(['adminStaffProfile']) ?? $target;
        });
    }

    public function disable(User $actor, int $targetId, string $reason): User
    {
        $this->authorization->assert($actor, AdminPermission::StaffManage);

        return DB::transaction(function () use ($actor, $targetId, $reason): User {
            $target = $this->lockStaffOrFail($targetId);
            $profile = AdminStaffProfile::query()->where('user_id', $target->id)->lockForUpdate()->firstOrFail();

            if ($actor->id === $target->id) {
                throw new AuthorizationException('You cannot disable your own staff account.');
            }

            if ($target->status === AccountStatus::Suspended) {
                throw new HttpResponseException(ApiResponse::error(
                    ApiErrorCode::BUSINESS_VALIDATION,
                    'This staff account is already disabled.',
                    422,
                ));
            }

            if ($target->status === AccountStatus::Banned) {
                throw new HttpResponseException(ApiResponse::error(
                    ApiErrorCode::BUSINESS_VALIDATION,
                    'Banned staff accounts cannot be disabled through this action.',
                    422,
                ));
            }

            if ($profile->staff_role === AdminStaffRole::SuperAdmin) {
                $this->assertNotLastEffectiveSuperAdmin($target->id);
            }

            $previousStatus = $target->status;
            $target->status = AccountStatus::Suspended;
            $target->save();

            $statusEvent = new UserStatusEvent;
            $statusEvent->actor_user_id = $actor->id;
            $statusEvent->target_user_id = $target->id;
            $statusEvent->action = UserStatusAction::Suspend;
            $statusEvent->previous_status = $previousStatus;
            $statusEvent->new_status = AccountStatus::Suspended;
            $statusEvent->reason = $reason;
            $statusEvent->save();

            $this->recordEvent(
                actor: $actor,
                action: AdminStaffEventAction::Disabled,
                targetUserId: $target->id,
                previousStaffRole: $profile->staff_role,
                newStaffRole: $profile->staff_role,
                previousStatus: $previousStatus,
                newStatus: AccountStatus::Suspended,
                reason: $reason,
            );

            $this->revokeAccess($target);

            return $target->fresh(['adminStaffProfile']) ?? $target;
        });
    }

    public function restore(User $actor, int $targetId, string $reason): User
    {
        $this->authorization->assert($actor, AdminPermission::StaffManage);

        return DB::transaction(function () use ($actor, $targetId, $reason): User {
            $target = $this->lockStaffOrFail($targetId);
            $profile = AdminStaffProfile::query()->where('user_id', $target->id)->lockForUpdate()->firstOrFail();

            if ($target->status !== AccountStatus::Suspended) {
                throw new HttpResponseException(ApiResponse::error(
                    ApiErrorCode::BUSINESS_VALIDATION,
                    'Only suspended staff accounts can be restored through this action.',
                    422,
                ));
            }

            $previousStatus = $target->status;
            $target->status = AccountStatus::Active;
            $target->save();

            $statusEvent = new UserStatusEvent;
            $statusEvent->actor_user_id = $actor->id;
            $statusEvent->target_user_id = $target->id;
            $statusEvent->action = UserStatusAction::Restore;
            $statusEvent->previous_status = $previousStatus;
            $statusEvent->new_status = AccountStatus::Active;
            $statusEvent->reason = $reason;
            $statusEvent->save();

            $this->recordEvent(
                actor: $actor,
                action: AdminStaffEventAction::Restored,
                targetUserId: $target->id,
                previousStaffRole: $profile->staff_role,
                newStaffRole: $profile->staff_role,
                previousStatus: $previousStatus,
                newStatus: AccountStatus::Active,
                reason: $reason,
            );

            return $target->fresh(['adminStaffProfile']) ?? $target;
        });
    }

    /**
     * Non-production UAT fallback: create staff directly with a password.
     */
    public function createDirect(User $actor, string $name, string $email, string $password, AdminStaffRole $staffRole): User
    {
        if (app()->environment('production')) {
            throw new AuthorizationException('Direct staff creation is not available in production.');
        }

        $this->authorization->assert($actor, AdminPermission::StaffManage);

        $email = strtolower(trim($email));

        return DB::transaction(function () use ($actor, $name, $email, $password, $staffRole): User {
            if (User::query()->where('email', $email)->lockForUpdate()->exists()) {
                throw ValidationException::withMessages([
                    'email' => ['An account with this email already exists.'],
                ]);
            }

            $user = new User;
            $user->name = trim($name);
            $user->email = $email;
            $user->password = $password;
            $user->role = Role::Admin;
            $user->status = AccountStatus::Active;
            $user->email_verified_at = now();
            $user->save();

            $profile = new AdminStaffProfile;
            $profile->user_id = $user->id;
            $profile->staff_role = $staffRole;
            $profile->created_by_user_id = $actor->id;
            $profile->save();

            $this->recordEvent(
                actor: $actor,
                action: AdminStaffEventAction::InvitationAccepted,
                targetUserId: $user->id,
                newStaffRole: $staffRole,
                newStatus: AccountStatus::Active,
                meta: ['direct_create' => true, 'email' => $email],
            );

            return $user->fresh(['adminStaffProfile']) ?? $user;
        });
    }

    public function ensureSuperAdminProfile(User $user, ?User $createdBy = null): AdminStaffProfile
    {
        if (! $user->isAdmin()) {
            throw new AuthorizationException('Only ADMIN users receive staff profiles.');
        }

        $existing = AdminStaffProfile::query()->where('user_id', $user->id)->first();
        if ($existing !== null) {
            return $existing;
        }

        $profile = new AdminStaffProfile;
        $profile->user_id = $user->id;
        $profile->staff_role = AdminStaffRole::SuperAdmin;
        $profile->created_by_user_id = $createdBy?->id;
        $profile->save();

        return $profile;
    }

    public function sendInvitationNotification(AdminStaffInvitation $invitation, string $plainToken): void
    {
        Notification::route('mail', $invitation->email)
            ->notify(new AdminStaffInvitationNotification($invitation, $plainToken));
    }

    private function assertNotLastEffectiveSuperAdmin(int $excludingUserId): void
    {
        $count = User::query()
            ->where('role', Role::Admin)
            ->where('status', AccountStatus::Active)
            ->where('id', '!=', $excludingUserId)
            ->whereHas('adminStaffProfile', function (Builder $builder): void {
                $builder->where('staff_role', AdminStaffRole::SuperAdmin);
            })
            ->lockForUpdate()
            ->count();

        if ($count < 1) {
            throw new HttpResponseException(ApiResponse::error(
                ApiErrorCode::BUSINESS_VALIDATION,
                'Cannot modify the last effective Super Admin.',
                422,
            ));
        }
    }

    private function revokeAccess(User $user): void
    {
        $user->tokens()->delete();

        if (Schema::hasTable('sessions')) {
            DB::table('sessions')->where('user_id', $user->id)->delete();
        }
    }

    /**
     * @return Builder<User>
     */
    private function staffQuery(): Builder
    {
        return User::query()
            ->where('role', Role::Admin)
            ->whereHas('adminStaffProfile');
    }

    private function findStaffOrFail(int $userId): User
    {
        $user = $this->staffQuery()->whereKey($userId)->first();
        if ($user === null) {
            throw (new ModelNotFoundException)->setModel(User::class, [$userId]);
        }

        return $user;
    }

    private function lockStaffOrFail(int $userId): User
    {
        $user = $this->staffQuery()->whereKey($userId)->lockForUpdate()->first();
        if ($user === null) {
            throw (new ModelNotFoundException)->setModel(User::class, [$userId]);
        }

        return $user;
    }

    /**
     * @param  array<string, mixed>|null  $meta
     */
    private function recordEvent(
        User $actor,
        AdminStaffEventAction $action,
        ?int $targetUserId,
        ?int $invitationId = null,
        ?AdminStaffRole $previousStaffRole = null,
        ?AdminStaffRole $newStaffRole = null,
        ?AccountStatus $previousStatus = null,
        ?AccountStatus $newStatus = null,
        ?string $reason = null,
        ?array $meta = null,
    ): void {
        $event = new AdminStaffEvent;
        $event->actor_user_id = $actor->id;
        $event->target_user_id = $targetUserId;
        $event->invitation_id = $invitationId;
        $event->action = $action;
        $event->previous_staff_role = $previousStaffRole;
        $event->new_staff_role = $newStaffRole;
        $event->previous_status = $previousStatus;
        $event->new_status = $newStatus;
        $event->reason = $reason;
        $event->meta = $meta;
        $event->save();
    }
}
