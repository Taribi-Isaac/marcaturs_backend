<?php

namespace App\Services\Admin;

use App\Enums\AccountStatus;
use App\Enums\AdminPermission;
use App\Enums\CommissionStatus;
use App\Enums\DisputeStatus;
use App\Enums\Role;
use App\Enums\UserStatusAction;
use App\Models\User;
use App\Models\UserStatusEvent;
use App\Notifications\AccountStatusChangedNotification;
use App\Services\Verification\VerificationStatusCalculator;
use App\Support\Api\ApiErrorCode;
use App\Support\Api\ApiResponse;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class AdminUserService
{
    public function __construct(
        private readonly VerificationStatusCalculator $verification,
        private readonly AdminAuthorization $authorization,
    ) {}

    /**
     * @return LengthAwarePaginator<int, User>
     */
    public function list(
        User $actor,
        ?Role $role,
        ?AccountStatus $status,
        ?string $search,
        int $perPage,
    ): LengthAwarePaginator {
        $this->authorization->assert($actor, AdminPermission::UsersView);

        $query = $this->participantQuery()
            ->with(['businessProfile', 'ambassadorProfile'])
            ->orderByDesc('id');

        if ($role !== null) {
            $query->where('role', $role);
        }

        if ($status !== null) {
            $query->where('status', $status);
        }

        if ($search !== null && trim($search) !== '') {
            $this->applySearch($query, trim($search));
        }

        $paginator = $query->paginate($perPage);
        $this->attachListAggregates($paginator->getCollection());

        return $paginator;
    }

    public function show(User $actor, int $userId): User
    {
        $this->authorization->assert($actor, AdminPermission::UsersView);

        $user = $this->findParticipantOrFail($userId);
        $user->load(['businessProfile', 'ambassadorProfile', 'verificationSubmissions.requirement']);
        $this->attachDetailAggregates($user);

        return $user;
    }

    public function restrict(User $actor, int $targetId, string $reason): User
    {
        return $this->mutate($actor, $targetId, UserStatusAction::Restrict, $reason);
    }

    public function suspend(User $actor, int $targetId, string $reason): User
    {
        return $this->mutate($actor, $targetId, UserStatusAction::Suspend, $reason);
    }

    public function restore(User $actor, int $targetId, string $reason): User
    {
        return $this->mutate($actor, $targetId, UserStatusAction::Restore, $reason);
    }

    public function ban(User $actor, int $targetId, string $reason): User
    {
        return $this->mutate($actor, $targetId, UserStatusAction::Ban, $reason);
    }

    private function mutate(User $actor, int $targetId, UserStatusAction $action, string $reason): User
    {
        $this->authorization->assert($actor, AdminPermission::UsersManage);

        // ADMIN (including the acting Admin) is outside the Users domain — resolve as 404.
        $target = $this->findParticipantOrFail($targetId);

        if ($actor->id === $target->id) {
            throw new AuthorizationException('You cannot change your own account status.');
        }

        $newStatus = $this->resolveTransition($target->status, $action);

        $event = DB::transaction(function () use ($actor, $target, $action, $newStatus, $reason): UserStatusEvent {
            $locked = User::query()->whereKey($target->id)->lockForUpdate()->firstOrFail();

            if (! $this->isParticipant($locked)) {
                throw (new ModelNotFoundException)->setModel(User::class, [$target->id]);
            }

            if ($locked->status !== $target->status) {
                throw new HttpResponseException(ApiResponse::error(
                    ApiErrorCode::CONFLICT,
                    'The account status changed. Refresh and try again.',
                    409,
                ));
            }

            $expected = $this->resolveTransition($locked->status, $action);
            if ($expected !== $newStatus) {
                throw new HttpResponseException(ApiResponse::error(
                    ApiErrorCode::BUSINESS_VALIDATION,
                    'This account status transition is not allowed.',
                    422,
                ));
            }

            $previous = $locked->status;
            $locked->status = $newStatus;
            $locked->save();

            $event = new UserStatusEvent;
            $event->actor_user_id = $actor->id;
            $event->target_user_id = $locked->id;
            $event->action = $action;
            $event->previous_status = $previous;
            $event->new_status = $newStatus;
            $event->reason = $reason;
            $event->save();

            return $event;
        });

        $fresh = $target->fresh(['businessProfile', 'ambassadorProfile', 'verificationSubmissions.requirement']) ?? $target;

        if (in_array($newStatus, [AccountStatus::Suspended, AccountStatus::Banned], true)) {
            $fresh->tokens()->delete();
        }

        $fresh->notify(new AccountStatusChangedNotification(
            $event->id,
            $fresh->id,
        ));

        $this->attachDetailAggregates($fresh);

        return $fresh;
    }

    private function resolveTransition(AccountStatus $current, UserStatusAction $action): AccountStatus
    {
        $next = match ([$current, $action]) {
            [AccountStatus::Active, UserStatusAction::Restrict] => AccountStatus::Restricted,
            [AccountStatus::Active, UserStatusAction::Suspend] => AccountStatus::Suspended,
            [AccountStatus::Active, UserStatusAction::Ban] => AccountStatus::Banned,
            [AccountStatus::Restricted, UserStatusAction::Restore] => AccountStatus::Active,
            [AccountStatus::Restricted, UserStatusAction::Suspend] => AccountStatus::Suspended,
            [AccountStatus::Restricted, UserStatusAction::Ban] => AccountStatus::Banned,
            [AccountStatus::Suspended, UserStatusAction::Restore] => AccountStatus::Active,
            [AccountStatus::Suspended, UserStatusAction::Ban] => AccountStatus::Banned,
            [AccountStatus::Banned, UserStatusAction::Restore] => AccountStatus::Restricted,
            default => null,
        };

        if ($next === null) {
            throw new HttpResponseException(ApiResponse::error(
                ApiErrorCode::BUSINESS_VALIDATION,
                'This account status transition is not allowed.',
                422,
            ));
        }

        return $next;
    }

    private function findParticipantOrFail(int $userId): User
    {
        $user = $this->participantQuery()->whereKey($userId)->first();

        if ($user === null) {
            throw (new ModelNotFoundException)->setModel(User::class, [$userId]);
        }

        return $user;
    }

    /**
     * @return Builder<User>
     */
    private function participantQuery(): Builder
    {
        return User::query()->whereIn('role', [Role::Business->value, Role::Ambassador->value]);
    }

    private function isParticipant(User $user): bool
    {
        return $user->isBusiness() || $user->isAmbassador();
    }

    /**
     * @param  Builder<User>  $query
     */
    private function applySearch(Builder $query, string $term): void
    {
        $like = '%'.addcslashes($term, '%_\\').'%';

        $query->where(function (Builder $outer) use ($like): void {
            $outer->where('users.name', 'like', $like)
                ->orWhere('users.email', 'like', $like)
                ->orWhereHas('businessProfile', function (Builder $profile) use ($like): void {
                    $profile->where('legal_name', 'like', $like)
                        ->orWhere('trading_name', 'like', $like);
                })
                ->orWhereHas('ambassadorProfile', function (Builder $profile) use ($like): void {
                    $profile->where('display_name', 'like', $like);
                });
        });
    }

    /**
     * @param  Collection<int, User>  $users
     */
    private function attachListAggregates($users): void
    {
        if ($users->isEmpty()) {
            return;
        }

        $ids = $users->pluck('id')->all();
        $verification = $this->verification->overallMany($users);
        $counts = $this->relationshipCounts($ids);

        foreach ($users as $user) {
            $user->setAttribute('admin_verification_status', $verification[$user->id] ?? null);
            $user->setAttribute('admin_relationship_counts', $counts[$user->id] ?? $this->emptyCounts());
        }
    }

    private function attachDetailAggregates(User $user): void
    {
        $verification = $this->verification->overall($user);
        $counts = $this->relationshipCounts([$user->id]);
        $user->setAttribute('admin_verification_status', $verification);
        $user->setAttribute('admin_relationship_counts', $counts[$user->id] ?? $this->emptyCounts());
        $user->setAttribute(
            'admin_verification_submissions',
            $user->verificationSubmissions
                ->sortByDesc('id')
                ->take(20)
                ->values()
                ->map(fn ($submission) => [
                    'id' => $submission->id,
                    'requirement_id' => $submission->verification_requirement_id,
                    'status' => $submission->status->value,
                    'current_version' => $submission->current_version,
                    'submitted_at' => $submission->submitted_at?->toIso8601String(),
                    'reviewed_at' => $submission->reviewed_at?->toIso8601String(),
                ])
                ->all(),
        );
    }

    /**
     * @param  list<int>  $userIds
     * @return array<int, array<string, int>>
     */
    private function relationshipCounts(array $userIds): array
    {
        $result = [];
        foreach ($userIds as $id) {
            $result[$id] = $this->emptyCounts();
        }

        foreach (
            DB::table('campaigns')
                ->selectRaw('user_id, count(*) as aggregate')
                ->whereIn('user_id', $userIds)
                ->groupBy('user_id')
                ->get() as $row
        ) {
            $result[(int) $row->user_id]['campaigns'] = (int) $row->aggregate;
        }

        foreach ($userIds as $userId) {
            $result[$userId]['deals'] = (int) DB::table('deals')
                ->where(function ($query) use ($userId): void {
                    $query->where('business_user_id', $userId)
                        ->orWhere('ambassador_user_id', $userId);
                })
                ->count();

            $result[$userId]['open_disputes'] = (int) DB::table('disputes')
                ->whereIn('status', DisputeStatus::openValues())
                ->where(function ($query) use ($userId): void {
                    $query->where('reporter_user_id', $userId)
                        ->orWhere('accused_user_id', $userId);
                })
                ->count();

            $result[$userId]['commissions_due'] = (int) DB::table('commissions')
                ->where('status', CommissionStatus::Due->value)
                ->where(function ($query) use ($userId): void {
                    $query->where('business_user_id', $userId)
                        ->orWhere('ambassador_user_id', $userId);
                })
                ->count();

            $result[$userId]['commissions_overdue'] = (int) DB::table('commissions')
                ->where('status', CommissionStatus::Due->value)
                ->whereNotNull('due_at')
                ->where('due_at', '<', now())
                ->where(function ($query) use ($userId): void {
                    $query->where('business_user_id', $userId)
                        ->orWhere('ambassador_user_id', $userId);
                })
                ->count();
        }

        return $result;
    }

    /**
     * @return array{campaigns: int, deals: int, open_disputes: int, commissions_due: int, commissions_overdue: int}
     */
    private function emptyCounts(): array
    {
        return [
            'campaigns' => 0,
            'deals' => 0,
            'open_disputes' => 0,
            'commissions_due' => 0,
            'commissions_overdue' => 0,
        ];
    }
}
