<?php

namespace App\Http\Resources\Api\V1;

use App\Models\CertificationProgramme;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin CertificationProgramme
 */
class CertificationProgrammeResource extends JsonResource
{
    public function __construct($resource, private readonly bool $admin = false)
    {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'learning_objectives' => $this->learning_objectives,
            'status' => $this->status->value,
            'current_published_version_id' => $this->current_published_version_id,
            'current_published_version' => $this->when(
                $this->relationLoaded('currentPublishedVersion') && $this->currentPublishedVersion !== null,
                fn () => (new CertificationProgrammeVersionResource($this->currentPublishedVersion, $this->admin))
                    ->resolve($request),
            ),
            'versions' => $this->when(
                $this->admin && $this->relationLoaded('versions'),
                fn () => $this->versions
                    ->map(fn ($version) => (new CertificationProgrammeVersionResource($version, true))->resolve($request))
                    ->values()
                    ->all(),
            ),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->when($this->admin, $this->updated_at?->toIso8601String()),
            'created_by_user_id' => $this->when($this->admin, $this->created_by_user_id),
        ];
    }
}
