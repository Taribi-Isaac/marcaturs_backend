<?php

namespace App\Http\Resources\Api\V1;

use App\Models\CertificationModule;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin CertificationModule
 */
class CertificationModuleResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'programme_version_id' => $this->programme_version_id,
            'title' => $this->title,
            'description' => $this->description,
            'sort_order' => $this->sort_order,
            'lessons' => $this->when(
                $this->relationLoaded('lessons'),
                fn () => CertificationLessonResource::collection($this->lessons)->resolve($request),
            ),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
