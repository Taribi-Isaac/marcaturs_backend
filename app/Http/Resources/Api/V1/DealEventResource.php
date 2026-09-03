<?php

namespace App\Http\Resources\Api\V1;

use App\Models\DealEvent;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin DealEvent
 */
class DealEventResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type->value,
            'actor' => $this->actor === null ? null : [
                'id' => $this->actor->id,
                'role' => $this->actor->role->value,
            ],
            'previous_status' => $this->previous_status?->value,
            'new_status' => $this->new_status->value,
            'metadata' => $this->metadata ?? [],
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
