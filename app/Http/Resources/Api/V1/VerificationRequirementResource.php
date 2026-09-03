<?php

namespace App\Http\Resources\Api\V1;

use App\Models\VerificationRequirement;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin VerificationRequirement
 */
class VerificationRequirementResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'participant_type' => $this->participant_type->value,
            'requirement_type' => $this->requirement_type->value,
            'is_required' => $this->is_required,
            'is_active' => $this->is_active,
            'sort_order' => $this->sort_order,
        ];
    }
}
