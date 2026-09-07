<?php

namespace App\Http\Resources\Api\V1;

use App\Models\CampaignFeaturedPackage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin CampaignFeaturedPackage
 */
class CampaignFeaturedPackageResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'duration_days' => $this->duration_days,
            'amount_minor' => $this->amount_minor,
            'currency' => $this->currency,
            'is_active' => $this->when(isset($this->is_active) && $request->user()?->isAdmin(), $this->is_active),
            'sort_order' => $this->when($request->user()?->isAdmin(), $this->sort_order),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
