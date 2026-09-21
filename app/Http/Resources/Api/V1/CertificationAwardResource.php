<?php

namespace App\Http\Resources\Api\V1;

use App\Models\CertificationAward;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin CertificationAward
 */
class CertificationAwardResource extends JsonResource
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
        return [
            'id' => $this->id,
            'status' => $this->status->value,
            'user_id' => $this->user_id,
            'programme_id' => $this->programme_id,
            'programme_version_id' => $this->programme_version_id,
            'enrollment_id' => $this->enrollment_id,
            'assessment_attempt_id' => $this->assessment_attempt_id,
            'awarded_at' => $this->awarded_at?->toIso8601String(),
            'programme' => $this->when(
                $this->relationLoaded('programme') && $this->programme !== null,
                fn () => [
                    'id' => $this->programme->id,
                    'name' => $this->programme->name,
                    'status' => $this->programme->status->value,
                ],
            ),
            'programme_version' => $this->when(
                $this->relationLoaded('programmeVersion') && $this->programmeVersion !== null,
                fn () => [
                    'id' => $this->programmeVersion->id,
                    'version_number' => $this->programmeVersion->version_number,
                    'status' => $this->programmeVersion->status->value,
                ],
            ),
            'assessment_attempt' => $this->when(
                $this->relationLoaded('assessmentAttempt') && $this->assessmentAttempt !== null,
                fn () => [
                    'id' => $this->assessmentAttempt->id,
                    'attempt_number' => $this->assessmentAttempt->attempt_number,
                    'score_percent' => $this->assessmentAttempt->score_percent !== null
                        ? (string) $this->assessmentAttempt->score_percent
                        : null,
                    'passed' => $this->assessmentAttempt->passed,
                    'submitted_at' => $this->assessmentAttempt->submitted_at?->toIso8601String(),
                ],
            ),
            'user' => $this->when(
                $this->admin && $this->relationLoaded('user') && $this->user !== null,
                fn () => [
                    'id' => $this->user->id,
                    'name' => $this->user->name,
                    'email' => $this->user->email,
                ],
            ),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
