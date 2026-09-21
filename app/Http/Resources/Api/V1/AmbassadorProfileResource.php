<?php

namespace App\Http\Resources\Api\V1;

use App\Models\AmbassadorProfile;
use App\Support\Certification\AmbassadorCertificationRepresentation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin AmbassadorProfile
 */
class AmbassadorProfileResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'display_name' => $this->display_name,
            'profile_description' => $this->profile_description,
            'location' => $this->location,
            'skills' => $this->skills ?? [],
            'marketing_interests' => $this->marketing_interests ?? [],
            'experience' => $this->experience,
            // Computed from Awards — not editable profile state; distinct from verification.
            'certification' => AmbassadorCertificationRepresentation::forUserId((int) $this->user_id),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
