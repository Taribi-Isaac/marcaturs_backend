<?php

namespace App\Services\Profiles;

use App\Models\BusinessProfile;
use App\Models\User;
use App\Support\Api\ApiErrorCode;
use App\Support\Api\ApiResponse;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Exceptions\HttpResponseException;

class BusinessProfileService
{
    public function show(User $user): BusinessProfile
    {
        $this->assertBusiness($user);

        return $user->businessProfile ?? throw new ModelNotFoundException;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(User $user, array $attributes): BusinessProfile
    {
        $this->assertBusiness($user);

        if ($user->businessProfile()->exists()) {
            throw new HttpResponseException(ApiResponse::error(
                ApiErrorCode::CONFLICT,
                'A business profile already exists for this account.',
                409,
            ));
        }

        return $user->businessProfile()->create($this->profileAttributes($attributes));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(User $user, array $attributes): BusinessProfile
    {
        $this->assertBusiness($user);

        $profile = $user->businessProfile ?? throw new ModelNotFoundException;

        $profile->fill($this->profileAttributes($attributes));
        $profile->save();

        return $profile->refresh();
    }

    private function assertBusiness(User $user): void
    {
        if (! $user->isBusiness()) {
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
            'legal_name',
            'trading_name',
            'description',
            'category',
            'address',
            'operating_location',
            'contact_email',
            'contact_phone',
            'website',
            'social_links',
        ])->all();
    }
}
