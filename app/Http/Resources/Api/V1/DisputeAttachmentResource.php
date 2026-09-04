<?php

namespace App\Http\Resources\Api\V1;

use App\Models\DisputeAttachment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin DisputeAttachment
 */
class DisputeAttachmentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'uploader' => $this->whenLoaded('uploader', fn () => [
                'id' => $this->uploader->id,
                'role' => $this->uploader->role->value,
            ]),
            'original_filename' => $this->original_filename,
            'mime_type' => $this->mime_type,
            'size_bytes' => $this->size_bytes,
            'note' => $this->note,
            'has_file' => $this->hasFile(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
