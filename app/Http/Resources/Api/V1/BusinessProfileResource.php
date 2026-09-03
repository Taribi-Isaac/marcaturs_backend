<?php

namespace App\Http\Resources\Api\V1;

use App\Models\BusinessProfile;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin BusinessProfile
 */
class BusinessProfileResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'legal_name' => $this->legal_name,
            'trading_name' => $this->trading_name,
            'description' => $this->description,
            'category' => $this->category,
            'address' => $this->address,
            'operating_location' => $this->operating_location,
            'contact_email' => $this->contact_email,
            'contact_phone' => $this->contact_phone,
            'website' => $this->website,
            'social_links' => $this->social_links ?? (object) [],
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
