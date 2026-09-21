<?php

namespace App\Services\Campaigns;

use App\Enums\AdminPermission;
use App\Models\Campaign;
use App\Models\CampaignCover;
use App\Models\User;
use App\Services\Admin\AdminAuthorization;
use App\Support\Api\ApiErrorCode;
use App\Support\Api\ApiResponse;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class CampaignCoverService
{
    public function __construct(
        private readonly CampaignCoverStore $files,
        private readonly CampaignDiscoveryService $discovery,
        private readonly AdminAuthorization $authorization,
    ) {}

    public function showForOwner(User $user, Campaign $campaign): CampaignCover
    {
        $this->assertOwner($user, $campaign);

        return $this->requireCover($campaign);
    }

    public function showForAdmin(User $admin, Campaign $campaign): CampaignCover
    {
        $this->authorization->assert($admin, AdminPermission::CampaignsView);

        return $this->requireCover($campaign);
    }

    public function upsert(User $user, Campaign $campaign, UploadedFile $file): CampaignCover
    {
        $this->assertOwner($user, $campaign);

        $stored = $this->files->store($campaign, $file);
        $existing = $campaign->cover()->first();
        $oldDisk = $existing?->disk;
        $oldPath = $existing?->path;

        try {
            DB::transaction(function () use ($user, $campaign, $stored, $existing): void {
                if ($existing !== null) {
                    $existing->uploaded_by = $user->id;
                    $existing->disk = $stored['disk'];
                    $existing->path = $stored['path'];
                    $existing->original_filename = $stored['original_filename'];
                    $existing->mime_type = $stored['mime_type'];
                    $existing->size_bytes = $stored['size_bytes'];
                    $existing->save();

                    return;
                }

                $cover = new CampaignCover;
                $cover->campaign_id = $campaign->id;
                $cover->uploaded_by = $user->id;
                $cover->disk = $stored['disk'];
                $cover->path = $stored['path'];
                $cover->original_filename = $stored['original_filename'];
                $cover->mime_type = $stored['mime_type'];
                $cover->size_bytes = $stored['size_bytes'];
                $cover->save();
            });
        } catch (Throwable $exception) {
            $this->files->deleteFile($stored['disk'], $stored['path']);
            throw $exception;
        }

        if ($oldPath !== null && ($oldPath !== $stored['path'] || $oldDisk !== $stored['disk'])) {
            $this->files->deleteFile($oldDisk, $oldPath);
        }

        return $this->requireCover($campaign->fresh());
    }

    public function delete(User $user, Campaign $campaign): void
    {
        $this->assertOwner($user, $campaign);
        $cover = $this->requireCover($campaign);

        $disk = $cover->disk;
        $path = $cover->path;
        $cover->delete();
        $this->files->deleteFile($disk, $path);
    }

    public function downloadForOwner(User $user, Campaign $campaign): StreamedResponse
    {
        $this->assertOwner($user, $campaign);

        return $this->files->stream($this->requireCover($campaign));
    }

    public function downloadForAdmin(User $admin, Campaign $campaign): StreamedResponse
    {
        $this->authorization->assert($admin, AdminPermission::CampaignsView);

        return $this->files->stream($this->requireCover($campaign));
    }

    public function streamPublic(int $campaignId): StreamedResponse
    {
        try {
            $campaign = $this->discovery->show($campaignId);
        } catch (ModelNotFoundException) {
            throw new ModelNotFoundException;
        }

        $cover = $campaign->cover;

        if ($cover === null) {
            throw new ModelNotFoundException;
        }

        return $this->files->stream($cover);
    }

    private function requireCover(Campaign $campaign): CampaignCover
    {
        $cover = $campaign->cover()->first();

        if ($cover === null) {
            throw new HttpResponseException(ApiResponse::error(
                ApiErrorCode::NOT_FOUND,
                'The requested resource was not found.',
                404,
            ));
        }

        return $cover->setRelation('campaign', $campaign);
    }

    private function assertOwner(User $user, Campaign $campaign): void
    {
        if (! $user->isBusiness() || $campaign->user_id !== $user->id) {
            throw new AuthorizationException('You are not authorized to perform this action.');
        }
    }
}
