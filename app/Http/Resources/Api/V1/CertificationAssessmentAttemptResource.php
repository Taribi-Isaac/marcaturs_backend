<?php

namespace App\Http\Resources\Api\V1;

use App\Enums\CertificationAssessmentAttemptStatus;
use App\Models\CertificationAssessmentAttempt;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin CertificationAssessmentAttempt
 */
class CertificationAssessmentAttemptResource extends JsonResource
{
    public function __construct($resource, private readonly bool $admin = false)
    {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $inProgress = $this->status === CertificationAssessmentAttemptStatus::InProgress;

        return [
            'id' => $this->id,
            'enrollment_id' => $this->enrollment_id,
            'assessment_id' => $this->assessment_id,
            'programme_version_id' => $this->programme_version_id,
            'attempt_number' => $this->attempt_number,
            'status' => $this->status->value,
            'started_at' => $this->started_at?->toIso8601String(),
            'submitted_at' => $this->submitted_at?->toIso8601String(),
            'pass_mark_percent' => (string) $this->pass_mark_percent,
            'correct_count' => $this->when(! $inProgress, $this->correct_count),
            'total_questions' => $this->when(! $inProgress, $this->total_questions),
            'score_percent' => $this->when(
                ! $inProgress && $this->score_percent !== null,
                fn () => (string) $this->score_percent,
            ),
            'passed' => $this->when(! $inProgress, $this->passed),
            'assessment' => $this->when(
                $this->relationLoaded('assessment') && $this->assessment !== null,
                function () use ($request, $inProgress) {
                    if ($inProgress) {
                        return (new CertificationAssessmentResource(
                            $this->assessment,
                            admin: false,
                            includeQuestions: true,
                        ))->resolve($request);
                    }

                    return [
                        'id' => $this->assessment->id,
                        'title' => $this->assessment->title,
                        'programme_version_id' => $this->assessment->programme_version_id,
                    ];
                },
            ),
            'answers' => $this->when(
                $this->relationLoaded('answers'),
                fn () => $this->answers->map(function ($answer) {
                    $row = [
                        'question_id' => $answer->question_id,
                        'selected_option_id' => $answer->selected_option_id,
                    ];
                    if ($this->admin) {
                        $row['is_correct'] = $answer->is_correct;
                    }

                    return $row;
                })->values()->all(),
            ),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
