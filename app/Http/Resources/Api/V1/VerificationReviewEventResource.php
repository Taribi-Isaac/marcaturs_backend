<?php

namespace App\Http\Resources\Api\V1;

use App\Models\VerificationReviewEvent;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin VerificationReviewEvent
 */
class VerificationReviewEventResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'actor_id' => $this->actor_id,
            'action' => $this->action->value,
            'previous_status' => $this->previous_status?->value,
            'new_status' => $this->new_status->value,
            'reason' => $this->reason,
            'reviewer_notes' => $this->getAttribute('reviewer_notes'),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
