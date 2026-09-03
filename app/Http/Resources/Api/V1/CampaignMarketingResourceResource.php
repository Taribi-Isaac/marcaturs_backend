<?php

namespace App\Http\Resources\Api\V1;

use App\Models\CampaignMarketingResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin CampaignMarketingResource
 */
class CampaignMarketingResourceResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type->value,
            'title' => $this->title,
            'description' => $this->description,
            'mime_type' => $this->mime_type,
            'original_filename' => $this->when(
                $request->user()?->isAdmin()
                    || ($request->user()?->isBusiness() && $request->user()->id === $this->campaign?->user_id),
                $this->original_filename,
            ),
            'size_bytes' => $this->size_bytes,
            'sort_order' => $this->sort_order,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
