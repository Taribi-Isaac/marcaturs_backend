<?php

namespace App\Http\Resources\Api\V1;

use App\Models\DisputeEvent;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin DisputeEvent
 */
class DisputeEventResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $user = $request->user();
        $isAdmin = $user !== null && $user->isAdmin();

        $metadata = $this->metadata ?? [];
        if (! $isAdmin) {
            unset(
                $metadata['classification_note'],
                $metadata['note'],
                $metadata['close_note'],
                $metadata['reason'],
            );
        }

        return [
            'id' => $this->id,
            'type' => $this->type->value,
            'previous_status' => $this->previous_status?->value,
            'new_status' => $this->new_status->value,
            'actor' => $this->whenLoaded('actor', fn () => $this->actor === null ? null : [
                'id' => $this->actor->id,
                'role' => $this->actor->role->value,
            ]),
            'metadata' => $metadata,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
