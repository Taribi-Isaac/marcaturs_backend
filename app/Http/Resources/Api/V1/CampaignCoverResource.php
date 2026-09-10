<?php

namespace App\Http\Resources\Api\V1;

use App\Models\CampaignCover;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin CampaignCover
 */
class CampaignCoverResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'available' => true,
            'mime_type' => $this->mime_type,
            'size_bytes' => $this->size_bytes,
            'original_filename' => $this->original_filename,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
