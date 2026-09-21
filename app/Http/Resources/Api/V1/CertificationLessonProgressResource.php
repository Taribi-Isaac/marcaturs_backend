<?php

namespace App\Http\Resources\Api\V1;

use App\Models\CertificationLessonProgress;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin CertificationLessonProgress
 */
class CertificationLessonProgressResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'enrollment_id' => $this->enrollment_id,
            'lesson_id' => $this->lesson_id,
            'status' => $this->status->value,
            'started_at' => $this->started_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'lesson' => $this->when(
                $this->relationLoaded('lesson') && $this->lesson !== null,
                fn () => [
                    'id' => $this->lesson->id,
                    'title' => $this->lesson->title,
                    'is_required' => $this->lesson->is_required,
                ],
            ),
        ];
    }
}
