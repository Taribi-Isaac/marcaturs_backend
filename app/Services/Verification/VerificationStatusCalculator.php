<?php

namespace App\Services\Verification;

use App\Enums\OverallVerificationStatus;
use App\Enums\Role;
use App\Enums\VerificationSubmissionStatus;
use App\Models\User;
use App\Models\VerificationRequirement;
use App\Models\VerificationSubmission;
use Illuminate\Support\Collection;

class VerificationStatusCalculator
{
    public function overall(User $user): OverallVerificationStatus
    {
        return $this->overallMany(collect([$user]))[$user->id];
    }

    /**
     * @param  Collection<int, User>  $users
     * @return array<int, OverallVerificationStatus>
     */
    public function overallMany(Collection $users): array
    {
        $result = [];
        $byRole = $users->unique('id')->groupBy(fn (User $user) => $user->role->value);

        foreach ($byRole as $roleValue => $roleUsers) {
            $role = Role::from($roleValue);

            if ($role !== Role::Business && $role !== Role::Ambassador) {
                foreach ($roleUsers as $user) {
                    $result[$user->id] = OverallVerificationStatus::NotStarted;
                }

                continue;
            }

            $required = VerificationRequirement::query()
                ->active()
                ->forParticipant($role)
                ->where('is_required', true)
                ->get();

            if ($required->isEmpty()) {
                foreach ($roleUsers as $user) {
                    $result[$user->id] = OverallVerificationStatus::NotStarted;
                }

                continue;
            }

            $submissions = VerificationSubmission::query()
                ->whereIn('user_id', $roleUsers->pluck('id'))
                ->whereIn('verification_requirement_id', $required->pluck('id'))
                ->get()
                ->groupBy('user_id');

            foreach ($roleUsers as $user) {
                $result[$user->id] = $this->fromSubmissions(
                    $required,
                    $submissions->get($user->id, collect())->keyBy('verification_requirement_id'),
                );
            }
        }

        foreach ($users as $user) {
            $result[$user->id] ??= OverallVerificationStatus::NotStarted;
        }

        return $result;
    }

    /**
     * @param  Collection<int, VerificationRequirement>  $required
     * @param  Collection<int, VerificationSubmission>  $submissions
     */
    private function fromSubmissions(Collection $required, Collection $submissions): OverallVerificationStatus
    {
        if ($required->isEmpty()) {
            return OverallVerificationStatus::NotStarted;
        }

        $statuses = $required->map(
            fn (VerificationRequirement $requirement) => $submissions->get($requirement->id)?->status,
        );

        if ($statuses->every(fn ($status) => $status === VerificationSubmissionStatus::Approved)) {
            return OverallVerificationStatus::Verified;
        }

        if ($statuses->contains(fn ($status) => $status === null)) {
            return $submissions->isEmpty()
                ? OverallVerificationStatus::NotStarted
                : OverallVerificationStatus::Pending;
        }

        if ($statuses->contains(VerificationSubmissionStatus::MoreInformationRequired)) {
            return OverallVerificationStatus::MoreInformationRequired;
        }

        if ($statuses->contains(VerificationSubmissionStatus::Rejected)) {
            return OverallVerificationStatus::Rejected;
        }

        if ($statuses->contains(VerificationSubmissionStatus::UnderReview)) {
            return OverallVerificationStatus::UnderReview;
        }

        return OverallVerificationStatus::Pending;
    }

    /**
     * @return Collection<int, VerificationRequirement>
     */
    public function activeRequirements(Role $role): Collection
    {
        return VerificationRequirement::query()
            ->active()
            ->forParticipant($role)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
    }
}
