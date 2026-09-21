<?php

namespace App\Http\Resources\Api\V1;

use App\Models\CertificationAssessment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin CertificationAssessment
 */
class CertificationAssessmentResource extends JsonResource
{
    public function __construct($resource, private readonly bool $admin = false, private readonly bool $includeQuestions = true)
    {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'programme_version_id' => $this->programme_version_id,
            'title' => $this->title,
            'instructions' => $this->instructions,
            // Derived from Programme Version — not independently stored on the assessment (MH-BE-048).
            'pass_mark_percent' => $this->authoritativePassMarkPercent(),
            'question_count' => $this->when(
                $this->relationLoaded('questions'),
                fn () => $this->questions->count(),
            ),
            'questions' => $this->when(
                $this->includeQuestions && $this->relationLoaded('questions'),
                fn () => $this->questions->map(
                    fn ($question) => (new CertificationQuestionResource($question, $this->admin))->resolve($request),
                )->values()->all(),
            ),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->when($this->admin, $this->updated_at?->toIso8601String()),
        ];
    }
}
