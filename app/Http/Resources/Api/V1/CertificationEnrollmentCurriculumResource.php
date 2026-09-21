<?php

namespace App\Http\Resources\Api\V1;

use App\Enums\CertificationLessonProgressStatus;
use App\Models\CertificationEnrollment;
use App\Models\CertificationLesson;
use App\Models\CertificationLessonProgress;
use App\Models\CertificationModule;
use App\Models\CertificationProgrammeVersion;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read array{
 *     enrollment: CertificationEnrollment,
 *     programme_version: CertificationProgrammeVersion,
 *     modules: Collection<int, CertificationModule>,
 *     progress_by_lesson_id: array<int, CertificationLessonProgress>,
 *     assessment_eligibility: array<string, mixed>
 * } $resource
 */
class CertificationEnrollmentCurriculumResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var array<string, mixed> $payload */
        $payload = $this->resource;
        $progressByLesson = $payload['progress_by_lesson_id'];

        return [
            'enrollment' => (new CertificationEnrollmentResource(
                $payload['enrollment']->loadMissing(['programme', 'programmeVersion']),
            ))->resolve($request),
            'programme_version' => [
                'id' => $payload['programme_version']->id,
                'programme_id' => $payload['programme_version']->programme_id,
                'version_number' => $payload['programme_version']->version_number,
                'status' => $payload['programme_version']->status->value,
            ],
            'modules' => $payload['modules']->map(function (CertificationModule $module) use ($request, $progressByLesson) {
                return [
                    'id' => $module->id,
                    'programme_version_id' => $module->programme_version_id,
                    'title' => $module->title,
                    'description' => $module->description,
                    'sort_order' => $module->sort_order,
                    'lessons' => $module->lessons->map(function (CertificationLesson $lesson) use ($request, $progressByLesson) {
                        /** @var CertificationLessonProgress|null $row */
                        $row = $progressByLesson[$lesson->id] ?? null;

                        return [
                            'id' => $lesson->id,
                            'module_id' => $lesson->module_id,
                            'title' => $lesson->title,
                            'description' => $lesson->description,
                            'content_type' => $lesson->content_type->value,
                            'is_required' => $lesson->is_required,
                            'sort_order' => $lesson->sort_order,
                            'progress' => [
                                'status' => ($row?->status ?? CertificationLessonProgressStatus::NotStarted)->value,
                                'started_at' => $row?->started_at?->toIso8601String(),
                                'completed_at' => $row?->completed_at?->toIso8601String(),
                            ],
                            'resources' => CertificationResourceResource::collection($lesson->resources)->resolve($request),
                        ];
                    })->values()->all(),
                ];
            })->values()->all(),
            'assessment_eligibility' => $payload['assessment_eligibility'],
        ];
    }
}
