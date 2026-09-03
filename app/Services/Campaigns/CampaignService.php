<?php

namespace App\Services\Campaigns;

use App\Enums\CampaignStatus;
use App\Enums\Role;
use App\Models\Campaign;
use App\Models\Category;
use App\Models\User;
use App\Support\Api\ApiErrorCode;
use App\Support\Api\ApiResponse;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Collection;

class CampaignService
{
    public function index(User $user): Collection
    {
        $this->assertBusiness($user);

        return $user->campaigns()->with(['category', 'currentVersion'])->orderByDesc('id')->get();
    }

    public function show(User $user, Campaign $campaign): Campaign
    {
        $this->assertOwner($user, $campaign);

        return $campaign->load(['category', 'currentVersion']);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(User $user, array $attributes): Campaign
    {
        $this->assertBusiness($user);
        $this->assertProfileComplete($user);
        $category = $this->assignableCategory((int) $attributes['category_id']);

        $campaign = new Campaign;
        $campaign->user_id = $user->id;
        $campaign->category_id = $category->id;
        $campaign->title = (string) $attributes['title'];
        $campaign->status = CampaignStatus::Draft;
        $campaign->is_featured = false;
        $campaign->save();

        return $campaign->load(['category', 'currentVersion']);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(User $user, Campaign $campaign, array $attributes): Campaign
    {
        $this->assertOwner($user, $campaign);

        if (! $campaign->status->allowsBusinessMetadataEdit()) {
            throw new HttpResponseException(ApiResponse::error(
                ApiErrorCode::CONFLICT,
                'Only draft campaigns can be updated in this foundation.',
                409,
            ));
        }

        if (array_key_exists('category_id', $attributes)) {
            $campaign->category_id = $this->assignableCategory((int) $attributes['category_id'])->id;
        }

        if (array_key_exists('title', $attributes)) {
            $campaign->title = (string) $attributes['title'];
        }

        $campaign->save();

        return $campaign->refresh()->load(['category', 'currentVersion']);
    }

    private function assignableCategory(int $categoryId): Category
    {
        $category = Category::query()->whereKey($categoryId)->first();

        if ($category === null) {
            throw new ModelNotFoundException;
        }

        if (! $category->isAssignableToCampaigns()) {
            throw new HttpResponseException(ApiResponse::error(
                ApiErrorCode::BUSINESS_VALIDATION,
                'This category cannot be used for a campaign.',
                422,
            ));
        }

        return $category;
    }

    private function assertBusiness(User $user): void
    {
        if (! $user->isBusiness()) {
            throw new AuthorizationException('You are not authorized to perform this action.');
        }
    }

    private function assertOwner(User $user, Campaign $campaign): void
    {
        $this->assertBusiness($user);

        if ($campaign->user_id !== $user->id) {
            throw new AuthorizationException('You are not authorized to perform this action.');
        }
    }

    private function assertProfileComplete(User $user): void
    {
        $complete = match ($user->role) {
            Role::Business => $user->businessProfile()->exists(),
            default => false,
        };

        if (! $complete) {
            throw new HttpResponseException(ApiResponse::error(
                ApiErrorCode::BUSINESS_VALIDATION,
                'Complete your business profile before creating a campaign.',
                422,
            ));
        }
    }
}
