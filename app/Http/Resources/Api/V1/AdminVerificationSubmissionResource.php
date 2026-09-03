<?php

namespace App\Http\Resources\Api\V1;

use App\Models\VerificationSubmission;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin VerificationSubmission
 */
class AdminVerificationSubmissionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'user' => $this->whenLoaded('user', fn () => [
                'id' => $this->user->id,
                'name' => $this->user->name,
                'email' => $this->user->email,
                'role' => $this->user->role->value,
            ]),
            'requirement' => new AdminVerificationRequirementResource($this->whenLoaded('requirement')),
            'status' => $this->status->value,
            'text_value' => $this->text_value,
            'current_version' => $this->current_version,
            'review_reason' => $this->review_reason,
            'reviewer_notes' => $this->getAttribute('reviewer_notes'),
            'reviewed_at' => $this->reviewed_at?->toIso8601String(),
            'submitted_at' => $this->submitted_at?->toIso8601String(),
            'evidence' => VerificationEvidenceResource::collection($this->whenLoaded('evidence')),
        ];
    }
}
