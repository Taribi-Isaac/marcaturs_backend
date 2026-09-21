<?php

namespace App\Http\Resources\Api\V1;

use App\Models\CertificationLesson;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin CertificationLesson
 */
class CertificationLessonResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'module_id' => $this->module_id,
            'title' => $this->title,
            'description' => $this->description,
            'content_type' => $this->content_type->value,
            'is_required' => $this->is_required,
            'sort_order' => $this->sort_order,
            'resources' => $this->when(
                $this->relationLoaded('resources'),
                fn () => CertificationResourceResource::collection($this->resources)->resolve($request),
            ),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
