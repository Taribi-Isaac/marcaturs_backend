<?php

namespace App\Services\Campaigns;

use App\Models\Campaign;
use App\Models\CampaignMarketingResource;
use App\Models\User;
use App\Support\Api\ApiErrorCode;
use App\Support\Api\ApiResponse;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\UploadedFile;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CampaignMarketingResourceService
{
    public function __construct(
        private readonly CampaignMarketingResourceStore $files,
        private readonly CampaignDiscoveryService $discovery,
    ) {}

    /**
     * @return Collection<int, CampaignMarketingResource>
     */
    public function listForOwner(User $user, Campaign $campaign): Collection
    {
        $this->assertOwner($user, $campaign);

        return $this->withCampaign($campaign, $campaign->marketingResources()->get());
    }

    /**
     * @return Collection<int, CampaignMarketingResource>
     */
    public function listForAdmin(User $admin, Campaign $campaign): Collection
    {
        $this->assertAdmin($admin);

        return $this->withCampaign($campaign, $campaign->marketingResources()->get());
    }

    public function showForOwner(User $user, Campaign $campaign, CampaignMarketingResource $resource): CampaignMarketingResource
    {
        $this->assertOwner($user, $campaign);
        $this->assertBelongs($campaign, $resource);

        return $resource->setRelation('campaign', $campaign);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(User $user, Campaign $campaign, array $attributes, UploadedFile $file): CampaignMarketingResource
    {
        $this->assertOwner($user, $campaign);

        $stored = $this->files->store($campaign, $file);

        $resource = new CampaignMarketingResource;
        $resource->campaign_id = $campaign->id;
        $resource->uploaded_by = $user->id;
        $resource->type = $attributes['type'];
        $resource->title = (string) $attributes['title'];
        $resource->description = $attributes['description'] ?? null;
        $resource->disk = $stored['disk'];
        $resource->path = $stored['path'];
        $resource->original_filename = $stored['original_filename'];
        $resource->mime_type = $stored['mime_type'];
        $resource->size_bytes = $stored['size_bytes'];
        $resource->sort_order = (int) ($attributes['sort_order'] ?? 0);
        $resource->save();

        return $resource->fresh()->setRelation('campaign', $campaign);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(
        User $user,
        Campaign $campaign,
        CampaignMarketingResource $resource,
        array $attributes,
        ?UploadedFile $file,
    ): CampaignMarketingResource {
        $this->assertOwner($user, $campaign);
        $this->assertBelongs($campaign, $resource);

        if ($file !== null) {
            $stored = $this->files->replace($resource, $file);
            $resource->disk = $stored['disk'];
            $resource->path = $stored['path'];
            $resource->original_filename = $stored['original_filename'];
            $resource->mime_type = $stored['mime_type'];
            $resource->size_bytes = $stored['size_bytes'];
        }

        if (array_key_exists('title', $attributes)) {
            $resource->title = (string) $attributes['title'];
        }
        if (array_key_exists('description', $attributes)) {
            $resource->description = $attributes['description'];
        }
        if (array_key_exists('type', $attributes)) {
            $resource->type = $attributes['type'];
        }
        if (array_key_exists('sort_order', $attributes)) {
            $resource->sort_order = (int) $attributes['sort_order'];
        }

        $resource->save();

        return $resource->fresh()->setRelation('campaign', $campaign);
    }

    public function delete(User $user, Campaign $campaign, CampaignMarketingResource $resource): void
    {
        $this->assertOwner($user, $campaign);
        $this->assertBelongs($campaign, $resource);

        $this->files->deleteFile($resource);
        $resource->delete();
    }

    public function downloadForOwner(User $user, Campaign $campaign, CampaignMarketingResource $resource): StreamedResponse
    {
        $this->assertOwner($user, $campaign);
        $this->assertBelongs($campaign, $resource);

        return $this->files->stream($resource);
    }

    public function downloadForAdmin(User $admin, Campaign $campaign, CampaignMarketingResource $resource): StreamedResponse
    {
        $this->assertAdmin($admin);
        $this->assertBelongs($campaign, $resource);

        return $this->files->stream($resource);
    }

    public function downloadForAmbassador(User $user, int $campaignId, int $resourceId): StreamedResponse
    {
        if (! $user->isAmbassador()) {
            throw new AuthorizationException('You are not authorized to perform this action.');
        }

        try {
            $campaign = $this->discovery->show($campaignId);
        } catch (ModelNotFoundException) {
            throw new ModelNotFoundException;
        }

        $resource = $campaign->marketingResources()->whereKey($resourceId)->first();

        if ($resource === null) {
            throw new ModelNotFoundException;
        }

        return $this->files->stream($resource);
    }

    /**
     * @param  Collection<int, CampaignMarketingResource>  $resources
     * @return Collection<int, CampaignMarketingResource>
     */
    private function withCampaign(Campaign $campaign, Collection $resources): Collection
    {
        return $resources->each(fn (CampaignMarketingResource $resource) => $resource->setRelation('campaign', $campaign));
    }

    private function assertBelongs(Campaign $campaign, CampaignMarketingResource $resource): void
    {
        if ($resource->campaign_id !== $campaign->id) {
            throw new HttpResponseException(ApiResponse::error(
                ApiErrorCode::NOT_FOUND,
                'The requested resource was not found.',
                404,
            ));
        }
    }

    private function assertOwner(User $user, Campaign $campaign): void
    {
        if (! $user->isBusiness() || $campaign->user_id !== $user->id) {
            throw new AuthorizationException('You are not authorized to perform this action.');
        }
    }

    private function assertAdmin(User $user): void
    {
        if (! $user->isAdmin()) {
            throw new AuthorizationException('You are not authorized to perform this action.');
        }
    }
}
