<?php

namespace App\Http\Resources\Api\V1;

use App\Models\VerificationSubmission;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin VerificationSubmission
 */
class ParticipantVerificationSubmissionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'requirement_id' => $this->verification_requirement_id,
            'status' => $this->status->value,
            'text_value' => $this->text_value,
            'review_reason' => $this->review_reason,
            'current_version' => $this->current_version,
            'submitted_at' => $this->submitted_at?->toIso8601String(),
            'evidence' => VerificationEvidenceResource::collection($this->evidenceForCurrentVersion()),
        ];
    }
}
