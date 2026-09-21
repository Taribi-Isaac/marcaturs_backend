<?php

namespace App\Services\Certification;

use App\Enums\CertificationAdminEventAction;
use App\Enums\CertificationEnrollmentStatus;
use App\Enums\CertificationLessonProgressStatus;
use App\Models\CertificationAdminEvent;
use App\Models\CertificationEnrollment;
use App\Models\CertificationLesson;
use App\Models\CertificationLessonProgress;
use App\Models\CertificationModule;
use App\Models\CertificationProgrammeVersion;
use App\Models\CertificationResource;
use App\Models\User;
use App\Support\Api\ApiErrorCode;
use App\Support\Api\ApiResponse;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CertificationLearningService
{
    public function __construct(
        private readonly CertificationResourceStore $files,
    ) {}

    /**
     * @return array{
     *     enrollment: CertificationEnrollment,
     *     programme_version: CertificationProgrammeVersion,
     *     modules: Collection<int, CertificationModule>,
     *     progress_by_lesson_id: array<int, CertificationLessonProgress>,
     *     assessment_eligibility: array<string, mixed>
     * }
     */
    public function curriculum(User $user, CertificationEnrollment $enrollment): array
    {
        $enrollment = $this->assertOwnedActiveEnrollment($user, $enrollment);
        $version = $this->loadEnrolledVersion($enrollment);

        $modules = $version->modules()
            ->with(['lessons.resources'])
            ->orderBy('sort_order')
            ->get();

        $progress = $this->progressMap($enrollment);

        return [
            'enrollment' => $enrollment,
            'programme_version' => $version,
            'modules' => $modules,
            'progress_by_lesson_id' => $progress,
            'assessment_eligibility' => $this->eligibilityFor($enrollment, $modules, $progress),
        ];
    }

    /**
     * @return array{
     *     enrollment_id: int,
     *     programme_version_id: int,
     *     lessons: list<array<string, mixed>>,
     *     assessment_eligibility: array<string, mixed>
     * }
     */
    public function progress(User $user, CertificationEnrollment $enrollment): array
    {
        $enrollment = $this->assertOwnedActiveEnrollment($user, $enrollment);
        $version = $this->loadEnrolledVersion($enrollment);
        $modules = $version->modules()->with('lessons')->orderBy('sort_order')->get();
        $progress = $this->progressMap($enrollment);

        $lessons = [];
        foreach ($modules as $module) {
            foreach ($module->lessons as $lesson) {
                $row = $progress[$lesson->id] ?? null;
                $lessons[] = [
                    'lesson_id' => $lesson->id,
                    'module_id' => $module->id,
                    'title' => $lesson->title,
                    'is_required' => $lesson->is_required,
                    'status' => ($row?->status ?? CertificationLessonProgressStatus::NotStarted)->value,
                    'started_at' => $row?->started_at?->toIso8601String(),
                    'completed_at' => $row?->completed_at?->toIso8601String(),
                ];
            }
        }

        return [
            'enrollment_id' => $enrollment->id,
            'programme_version_id' => $version->id,
            'lessons' => $lessons,
            'assessment_eligibility' => $this->eligibilityFor($enrollment, $modules, $progress),
        ];
    }

    public function markLessonComplete(
        User $user,
        CertificationEnrollment $enrollment,
        CertificationLesson $lesson,
    ): CertificationLessonProgress {
        $enrollment = $this->assertOwnedActiveEnrollment($user, $enrollment);
        $this->assertLessonBelongsToEnrollment($enrollment, $lesson);

        try {
            $progress = DB::transaction(function () use ($user, $enrollment, $lesson) {
                $lockedEnrollment = CertificationEnrollment::query()
                    ->whereKey($enrollment->id)
                    ->lockForUpdate()
                    ->firstOrFail();
                $this->assertOwnedActiveEnrollment($user, $lockedEnrollment);

                $existing = CertificationLessonProgress::query()
                    ->where('enrollment_id', $lockedEnrollment->id)
                    ->where('lesson_id', $lesson->id)
                    ->lockForUpdate()
                    ->first();

                if ($existing !== null && $existing->status === CertificationLessonProgressStatus::Completed) {
                    return $existing;
                }

                $now = now();

                if ($existing === null) {
                    $existing = new CertificationLessonProgress;
                    $existing->enrollment_id = $lockedEnrollment->id;
                    $existing->lesson_id = $lesson->id;
                    $existing->started_at = $now;
                } elseif ($existing->started_at === null) {
                    $existing->started_at = $now;
                }

                $existing->status = CertificationLessonProgressStatus::Completed;
                $existing->completed_at = $existing->completed_at ?? $now;
                $existing->save();

                $this->recordLessonCompleted($user, $lockedEnrollment, $lesson, $existing);

                return $existing->refresh();
            });
        } catch (UniqueConstraintViolationException) {
            $progress = CertificationLessonProgress::query()
                ->where('enrollment_id', $enrollment->id)
                ->where('lesson_id', $lesson->id)
                ->firstOrFail();
        }

        return $progress;
    }

    public function downloadResource(
        User $user,
        CertificationEnrollment $enrollment,
        CertificationLesson $lesson,
        CertificationResource $resource,
    ): StreamedResponse {
        $enrollment = $this->assertOwnedActiveEnrollment($user, $enrollment);
        $this->assertLessonBelongsToEnrollment($enrollment, $lesson);

        if ((int) $resource->lesson_id !== (int) $lesson->id) {
            throw $this->notFound();
        }

        if (! $resource->hasPrivateFile()) {
            throw $this->notFound();
        }

        return $this->files->stream($resource);
    }

    /**
     * @return array{
     *     eligible: bool,
     *     required_lessons: int,
     *     completed_required_lessons: int,
     *     optional_lessons: int,
     *     completed_optional_lessons: int
     * }
     */
    public function assessmentEligibilityForEnrollment(CertificationEnrollment $enrollment): array
    {
        $version = $this->loadEnrolledVersion($enrollment);
        $modules = $version->modules()->with('lessons')->orderBy('sort_order')->get();
        $progress = $this->progressMap($enrollment);

        return $this->eligibilityFor($enrollment, $modules, $progress);
    }

    /**
     * @param  Collection<int, CertificationModule>  $modules
     * @param  array<int, CertificationLessonProgress>  $progress
     * @return array{
     *     eligible: bool,
     *     required_lessons: int,
     *     completed_required_lessons: int,
     *     optional_lessons: int,
     *     completed_optional_lessons: int
     * }
     */
    private function eligibilityFor(
        CertificationEnrollment $enrollment,
        Collection $modules,
        array $progress,
    ): array {
        unset($enrollment);

        $required = 0;
        $completedRequired = 0;
        $optional = 0;
        $completedOptional = 0;

        foreach ($modules as $module) {
            foreach ($module->lessons as $lesson) {
                $isCompleted = isset($progress[$lesson->id])
                    && $progress[$lesson->id]->status === CertificationLessonProgressStatus::Completed;

                if ($lesson->is_required) {
                    $required++;
                    if ($isCompleted) {
                        $completedRequired++;
                    }
                } else {
                    $optional++;
                    if ($isCompleted) {
                        $completedOptional++;
                    }
                }
            }
        }

        return [
            // FR-021/FR-025: assessment unlocks when every required lesson is complete.
            // Vacuous case (zero required lessons) is eligible; Product should still
            // configure required lessons before launch readiness.
            'eligible' => $completedRequired === $required,
            'required_lessons' => $required,
            'completed_required_lessons' => $completedRequired,
            'optional_lessons' => $optional,
            'completed_optional_lessons' => $completedOptional,
        ];
    }

    /**
     * @return array<int, CertificationLessonProgress>
     */
    private function progressMap(CertificationEnrollment $enrollment): array
    {
        return $enrollment->lessonProgress()
            ->get()
            ->keyBy(fn (CertificationLessonProgress $row) => (int) $row->lesson_id)
            ->all();
    }

    private function loadEnrolledVersion(CertificationEnrollment $enrollment): CertificationProgrammeVersion
    {
        $version = CertificationProgrammeVersion::query()
            ->whereKey($enrollment->programme_version_id)
            ->first();

        if ($version === null || (int) $version->programme_id !== (int) $enrollment->programme_id) {
            throw new HttpResponseException(ApiResponse::error(
                ApiErrorCode::SERVER_ERROR,
                'Enrollment programme version is unavailable.',
                500,
            ));
        }

        return $version;
    }

    public function assertOwnedActiveEnrollment(User $user, CertificationEnrollment $enrollment): CertificationEnrollment
    {
        if (! $user->isAmbassador()) {
            throw new AuthorizationException('You are not authorized to perform this action.');
        }

        if ((int) $enrollment->user_id !== (int) $user->id) {
            throw $this->notFound();
        }

        if ($enrollment->status !== CertificationEnrollmentStatus::Active) {
            throw new HttpResponseException(ApiResponse::error(
                ApiErrorCode::CONFLICT,
                'This certification enrollment is not active.',
                409,
            ));
        }

        return $enrollment;
    }

    private function assertLessonBelongsToEnrollment(
        CertificationEnrollment $enrollment,
        CertificationLesson $lesson,
    ): void {
        $lesson->loadMissing('module');

        if ($lesson->module === null) {
            throw $this->notFound();
        }

        if ((int) $lesson->module->programme_version_id !== (int) $enrollment->programme_version_id) {
            throw $this->notFound();
        }
    }

    private function recordLessonCompleted(
        User $user,
        CertificationEnrollment $enrollment,
        CertificationLesson $lesson,
        CertificationLessonProgress $progress,
    ): void {
        $event = new CertificationAdminEvent;
        $event->actor_user_id = $user->id;
        $event->programme_id = $enrollment->programme_id;
        $event->programme_version_id = $enrollment->programme_version_id;
        $event->action = CertificationAdminEventAction::LessonCompleted;
        $event->payload = [
            'enrollment_id' => $enrollment->id,
            'lesson_id' => $lesson->id,
            'progress_id' => $progress->id,
            'completed_at' => $progress->completed_at?->toIso8601String(),
        ];
        $event->save();
    }

    private function notFound(): HttpResponseException
    {
        return new HttpResponseException(ApiResponse::error(
            ApiErrorCode::NOT_FOUND,
            'The requested resource was not found.',
            404,
        ));
    }
}
