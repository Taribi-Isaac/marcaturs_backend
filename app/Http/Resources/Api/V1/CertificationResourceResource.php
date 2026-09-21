<?php

namespace App\Http\Resources\Api\V1;

use App\Models\CertificationResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin CertificationResource
 */
class CertificationResourceResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'lesson_id' => $this->lesson_id,
            'type' => $this->type->value,
            'title' => $this->title,
            'sort_order' => $this->sort_order,
            'body_text' => $this->body_text,
            'external_url' => $this->external_url,
            'original_filename' => $this->original_filename,
            'mime_type' => $this->mime_type,
            'size_bytes' => $this->size_bytes,
            'has_file' => $this->hasPrivateFile(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
