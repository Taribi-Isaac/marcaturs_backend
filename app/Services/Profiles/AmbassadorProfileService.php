<?php

namespace App\Services\Profiles;

use App\Models\AmbassadorProfile;
use App\Models\User;
use App\Support\Api\ApiErrorCode;
use App\Support\Api\ApiResponse;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Exceptions\HttpResponseException;

class AmbassadorProfileService
{
    public function show(User $user): AmbassadorProfile
    {
        $this->assertAmbassador($user);

        return $user->ambassadorProfile ?? throw new ModelNotFoundException;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(User $user, array $attributes): AmbassadorProfile
    {
        $this->assertAmbassador($user);

        if ($user->ambassadorProfile()->exists()) {
            throw new HttpResponseException(ApiResponse::error(
                ApiErrorCode::CONFLICT,
                'An ambassador profile already exists for this account.',
                409,
            ));
        }

        return $user->ambassadorProfile()->create($this->profileAttributes($attributes));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(User $user, array $attributes): AmbassadorProfile
    {
        $this->assertAmbassador($user);

        $profile = $user->ambassadorProfile ?? throw new ModelNotFoundException;

        $profile->fill($this->profileAttributes($attributes));
        $profile->save();

        return $profile->refresh();
    }

    private function assertAmbassador(User $user): void
    {
        if (! $user->isAmbassador()) {
            throw new AuthorizationException('You are not authorized to perform this action.');
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function profileAttributes(array $attributes): array
    {
        return collect($attributes)->only([
            'display_name',
            'profile_description',
            'location',
            'skills',
            'marketing_interests',
            'experience',
        ])->all();
    }
}
