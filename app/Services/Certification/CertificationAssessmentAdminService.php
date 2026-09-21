<?php

namespace App\Services\Certification;

use App\Enums\AdminPermission;
use App\Enums\CertificationAdminEventAction;
use App\Enums\CertificationQuestionType;
use App\Models\CertificationAdminEvent;
use App\Models\CertificationAssessment;
use App\Models\CertificationProgramme;
use App\Models\CertificationProgrammeVersion;
use App\Models\CertificationQuestion;
use App\Models\CertificationQuestionOption;
use App\Models\User;
use App\Services\Admin\AdminAuthorization;
use App\Support\Api\ApiErrorCode;
use App\Support\Api\ApiResponse;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CertificationAssessmentAdminService
{
    public function __construct(
        private readonly AdminAuthorization $authorization,
    ) {}

    public function show(
        User $admin,
        CertificationProgramme $programme,
        CertificationProgrammeVersion $version,
    ): CertificationAssessment {
        $this->authorization->assert($admin, AdminPermission::CertificationView);
        $this->assertVersionBelongs($programme, $version);

        $assessment = $version->assessment()->with(['questions.options', 'programmeVersion'])->first();
        if ($assessment === null) {
            throw $this->notFound();
        }

        return $assessment;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(
        User $admin,
        CertificationProgramme $programme,
        CertificationProgrammeVersion $version,
        array $attributes,
    ): CertificationAssessment {
        $this->authorization->assert($admin, AdminPermission::CertificationManage);
        $this->assertVersionBelongs($programme, $version);
        $this->assertVersionMutable($version);

        try {
            return DB::transaction(function () use ($admin, $programme, $version, $attributes) {
                $lockedVersion = $this->lockMutableVersion($programme, $version);

                if ($lockedVersion->assessment()->exists()) {
                    throw new HttpResponseException(ApiResponse::error(
                        ApiErrorCode::CONFLICT,
                        'This programme version already has a final assessment.',
                        409,
                    ));
                }

                $assessment = new CertificationAssessment;
                $assessment->programme_version_id = $lockedVersion->id;
                $assessment->title = (string) $attributes['title'];
                $assessment->instructions = $attributes['instructions'] ?? null;
                $assessment->configuration = null;
                $assessment->save();

                $this->recordEvent(
                    $admin,
                    $programme,
                    $lockedVersion,
                    CertificationAdminEventAction::AssessmentCreated,
                    [
                        'assessment_id' => $assessment->id,
                        'title' => $assessment->title,
                        'pass_mark_percent' => $lockedVersion->pass_mark_percent !== null
                            ? (string) $lockedVersion->pass_mark_percent
                            : null,
                    ],
                );

                return $assessment->refresh()->load(['questions.options', 'programmeVersion']);
            });
        } catch (UniqueConstraintViolationException) {
            throw new HttpResponseException(ApiResponse::error(
                ApiErrorCode::CONFLICT,
                'This programme version already has a final assessment.',
                409,
            ));
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(
        User $admin,
        CertificationProgramme $programme,
        CertificationProgrammeVersion $version,
        array $attributes,
    ): CertificationAssessment {
        $this->authorization->assert($admin, AdminPermission::CertificationManage);
        $this->assertVersionBelongs($programme, $version);
        $this->assertVersionMutable($version);

        return DB::transaction(function () use ($admin, $programme, $version, $attributes) {
            $lockedVersion = $this->lockMutableVersion($programme, $version);
            $assessment = $this->lockAssessmentForVersion($lockedVersion);

            if (array_key_exists('title', $attributes)) {
                $assessment->title = (string) $attributes['title'];
            }
            if (array_key_exists('instructions', $attributes)) {
                $assessment->instructions = $attributes['instructions'];
            }
            $assessment->save();

            $this->recordEvent(
                $admin,
                $programme,
                $lockedVersion,
                CertificationAdminEventAction::AssessmentUpdated,
                [
                    'assessment_id' => $assessment->id,
                    'title' => $assessment->title,
                    'pass_mark_percent' => $lockedVersion->pass_mark_percent !== null
                        ? (string) $lockedVersion->pass_mark_percent
                        : null,
                ],
            );

            return $assessment->refresh()->load(['questions.options', 'programmeVersion']);
        });
    }

    /**
     * @return Collection<int, CertificationQuestion>
     */
    public function indexQuestions(
        User $admin,
        CertificationProgramme $programme,
        CertificationProgrammeVersion $version,
    ): Collection {
        $this->authorization->assert($admin, AdminPermission::CertificationView);
        $assessment = $this->requireAssessment($programme, $version);

        return $assessment->questions()->with('options')->orderBy('sort_order')->get();
    }

    public function showQuestion(
        User $admin,
        CertificationProgramme $programme,
        CertificationProgrammeVersion $version,
        CertificationQuestion $question,
    ): CertificationQuestion {
        $this->authorization->assert($admin, AdminPermission::CertificationView);
        $this->assertQuestionChain($programme, $version, $question);

        return $question->load('options');
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function createQuestion(
        User $admin,
        CertificationProgramme $programme,
        CertificationProgrammeVersion $version,
        array $attributes,
    ): CertificationQuestion {
        $this->authorization->assert($admin, AdminPermission::CertificationManage);
        $this->assertVersionMutable($version);
        $this->assertSingleChoicePayload($attributes);

        try {
            return DB::transaction(function () use ($admin, $programme, $version, $attributes) {
                $lockedVersion = $this->lockMutableVersion($programme, $version);
                $assessment = $this->lockAssessmentForVersion($lockedVersion);

                $question = new CertificationQuestion;
                $question->assessment_id = $assessment->id;
                $question->prompt = (string) $attributes['prompt'];
                $question->type = CertificationQuestionType::from((string) $attributes['type']);
                $question->sort_order = (int) $assessment->questions()->max('sort_order') + 1;
                $question->save();

                $this->replaceOptions($question, $attributes['options']);

                $this->recordEvent(
                    $admin,
                    $programme,
                    $lockedVersion,
                    CertificationAdminEventAction::QuestionCreated,
                    [
                        'assessment_id' => $assessment->id,
                        'question_id' => $question->id,
                        'type' => $question->type->value,
                        'sort_order' => $question->sort_order,
                        'option_count' => count($attributes['options']),
                    ],
                );

                return $question->refresh()->load('options');
            });
        } catch (UniqueConstraintViolationException) {
            throw $this->orderingConflict();
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function updateQuestion(
        User $admin,
        CertificationProgramme $programme,
        CertificationProgrammeVersion $version,
        CertificationQuestion $question,
        array $attributes,
    ): CertificationQuestion {
        $this->authorization->assert($admin, AdminPermission::CertificationManage);
        $this->assertQuestionChain($programme, $version, $question);
        $this->assertVersionMutable($version);

        if (array_key_exists('type', $attributes) || array_key_exists('options', $attributes)) {
            $merged = [
                'type' => $attributes['type'] ?? $question->type->value,
                'options' => $attributes['options'] ?? null,
            ];
            if ($merged['options'] !== null) {
                $this->assertSingleChoicePayload($merged);
            } elseif (isset($attributes['type']) && $attributes['type'] !== $question->type->value) {
                throw ValidationException::withMessages([
                    'options' => ['Options are required when changing the question type.'],
                ]);
            }
        }

        try {
            return DB::transaction(function () use ($admin, $programme, $version, $question, $attributes) {
                $lockedVersion = $this->lockMutableVersion($programme, $version);
                $this->lockAssessmentForVersion($lockedVersion);
                $locked = CertificationQuestion::query()->whereKey($question->id)->lockForUpdate()->firstOrFail();
                $this->assertQuestionChain($programme, $version, $locked);

                if (array_key_exists('prompt', $attributes)) {
                    $locked->prompt = (string) $attributes['prompt'];
                }
                if (array_key_exists('type', $attributes)) {
                    $locked->type = CertificationQuestionType::from((string) $attributes['type']);
                }
                $locked->save();

                if (array_key_exists('options', $attributes)) {
                    $this->replaceOptions($locked, $attributes['options']);
                }

                $this->recordEvent(
                    $admin,
                    $programme,
                    $lockedVersion,
                    CertificationAdminEventAction::QuestionUpdated,
                    [
                        'assessment_id' => $locked->assessment_id,
                        'question_id' => $locked->id,
                        'type' => $locked->type->value,
                        'sort_order' => $locked->sort_order,
                    ],
                );

                return $locked->refresh()->load('options');
            });
        } catch (UniqueConstraintViolationException) {
            throw $this->orderingConflict();
        }
    }

    public function deleteQuestion(
        User $admin,
        CertificationProgramme $programme,
        CertificationProgrammeVersion $version,
        CertificationQuestion $question,
    ): void {
        $this->authorization->assert($admin, AdminPermission::CertificationManage);
        $this->assertQuestionChain($programme, $version, $question);
        $this->assertVersionMutable($version);

        DB::transaction(function () use ($admin, $programme, $version, $question): void {
            $lockedVersion = $this->lockMutableVersion($programme, $version);
            $assessment = $this->lockAssessmentForVersion($lockedVersion);
            $locked = CertificationQuestion::query()->whereKey($question->id)->lockForUpdate()->firstOrFail();
            $this->assertQuestionChain($programme, $version, $locked);

            $questionId = $locked->id;
            $locked->options()->delete();
            $locked->delete();
            $this->renumberQuestions($assessment);

            $this->recordEvent(
                $admin,
                $programme,
                $lockedVersion,
                CertificationAdminEventAction::QuestionDeleted,
                [
                    'assessment_id' => $assessment->id,
                    'question_id' => $questionId,
                ],
            );
        });
    }

    /**
     * @param  array{question_ids: list<int>}  $payload
     * @return Collection<int, CertificationQuestion>
     */
    public function reorderQuestions(
        User $admin,
        CertificationProgramme $programme,
        CertificationProgrammeVersion $version,
        array $payload,
    ): Collection {
        $this->authorization->assert($admin, AdminPermission::CertificationManage);
        $this->assertVersionMutable($version);

        try {
            return DB::transaction(function () use ($admin, $programme, $version, $payload) {
                $lockedVersion = $this->lockMutableVersion($programme, $version);
                $assessment = $this->lockAssessmentForVersion($lockedVersion);

                $existingIds = $assessment->questions()->orderBy('sort_order')->pluck('id')->map(fn ($id) => (int) $id)->all();
                $requestedIds = array_map('intval', $payload['question_ids']);
                sort($existingIds);
                $sortedRequested = $requestedIds;
                sort($sortedRequested);

                if ($existingIds !== $sortedRequested || count($requestedIds) !== count(array_unique($requestedIds))) {
                    throw new HttpResponseException(ApiResponse::error(
                        ApiErrorCode::BUSINESS_VALIDATION,
                        'question_ids must be an exact permutation of this assessment\'s questions.',
                        422,
                    ));
                }

                foreach ($requestedIds as $index => $questionId) {
                    CertificationQuestion::query()
                        ->whereKey($questionId)
                        ->where('assessment_id', $assessment->id)
                        ->update(['sort_order' => 1_000_000 + $index]);
                }
                foreach ($requestedIds as $index => $questionId) {
                    CertificationQuestion::query()
                        ->whereKey($questionId)
                        ->where('assessment_id', $assessment->id)
                        ->update(['sort_order' => $index + 1]);
                }

                $this->recordEvent(
                    $admin,
                    $programme,
                    $lockedVersion,
                    CertificationAdminEventAction::QuestionsReordered,
                    [
                        'assessment_id' => $assessment->id,
                        'question_ids' => $requestedIds,
                    ],
                );

                return $assessment->questions()->with('options')->orderBy('sort_order')->get();
            });
        } catch (UniqueConstraintViolationException) {
            throw $this->orderingConflict();
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function assertSingleChoicePayload(array $attributes): void
    {
        $type = CertificationQuestionType::tryFrom((string) ($attributes['type'] ?? ''));
        if ($type !== CertificationQuestionType::SingleChoice) {
            throw ValidationException::withMessages([
                'type' => ['Only single_choice questions are supported in this release.'],
            ]);
        }

        $options = $attributes['options'] ?? null;
        if (! is_array($options) || count($options) < 2) {
            throw ValidationException::withMessages([
                'options' => ['Single-choice questions require at least two options.'],
            ]);
        }

        $correctCount = 0;
        foreach ($options as $index => $option) {
            if (! is_array($option) || blank($option['label'] ?? null)) {
                throw ValidationException::withMessages([
                    "options.{$index}.label" => ['Each option requires a label.'],
                ]);
            }
            if (! empty($option['is_correct'])) {
                $correctCount++;
            }
        }

        if ($correctCount !== 1) {
            throw ValidationException::withMessages([
                'options' => ['Single-choice questions require exactly one correct option.'],
            ]);
        }
    }

    /**
     * @param  list<array{label: string, is_correct?: bool}>  $options
     */
    private function replaceOptions(CertificationQuestion $question, array $options): void
    {
        $question->options()->delete();

        foreach (array_values($options) as $index => $option) {
            $row = new CertificationQuestionOption;
            $row->question_id = $question->id;
            $row->label = (string) $option['label'];
            $row->is_correct = (bool) ($option['is_correct'] ?? false);
            $row->sort_order = $index + 1;
            $row->save();
        }
    }

    private function renumberQuestions(CertificationAssessment $assessment): void
    {
        foreach ($assessment->questions()->orderBy('sort_order')->orderBy('id')->get() as $index => $question) {
            $question->sort_order = 1_000_000 + $index;
            $question->save();
        }
        foreach ($assessment->questions()->orderBy('sort_order')->orderBy('id')->get() as $index => $question) {
            $question->sort_order = $index + 1;
            $question->save();
        }
    }

    private function requireAssessment(
        CertificationProgramme $programme,
        CertificationProgrammeVersion $version,
    ): CertificationAssessment {
        $this->assertVersionBelongs($programme, $version);
        $assessment = $version->assessment;
        if ($assessment === null) {
            throw $this->notFound();
        }

        return $assessment;
    }

    private function lockAssessmentForVersion(CertificationProgrammeVersion $version): CertificationAssessment
    {
        $assessment = CertificationAssessment::query()
            ->where('programme_version_id', $version->id)
            ->lockForUpdate()
            ->first();

        if ($assessment === null) {
            throw $this->notFound();
        }

        return $assessment;
    }

    private function lockMutableVersion(
        CertificationProgramme $programme,
        CertificationProgrammeVersion $version,
    ): CertificationProgrammeVersion {
        $locked = CertificationProgrammeVersion::query()->whereKey($version->id)->lockForUpdate()->firstOrFail();
        $this->assertVersionBelongs($programme, $locked);
        $this->assertVersionMutable($locked);

        return $locked;
    }

    private function assertVersionMutable(CertificationProgrammeVersion $version): void
    {
        if (! $version->status->isMutable()) {
            throw new HttpResponseException(ApiResponse::error(
                ApiErrorCode::CONFLICT,
                'Assessment belonging to published or unpublished programme versions is immutable.',
                409,
            ));
        }
    }

    private function assertVersionBelongs(
        CertificationProgramme $programme,
        CertificationProgrammeVersion $version,
    ): void {
        if ((int) $version->programme_id !== (int) $programme->id) {
            throw $this->notFound();
        }
    }

    private function assertQuestionChain(
        CertificationProgramme $programme,
        CertificationProgrammeVersion $version,
        CertificationQuestion $question,
    ): void {
        $assessment = $this->requireAssessment($programme, $version);
        if ((int) $question->assessment_id !== (int) $assessment->id) {
            throw $this->notFound();
        }
    }

    /**
     * @param  array<string, mixed>|null  $payload
     */
    private function recordEvent(
        User $admin,
        CertificationProgramme $programme,
        CertificationProgrammeVersion $version,
        CertificationAdminEventAction $action,
        ?array $payload = null,
    ): void {
        $event = new CertificationAdminEvent;
        $event->actor_user_id = $admin->id;
        $event->programme_id = $programme->id;
        $event->programme_version_id = $version->id;
        $event->action = $action;
        $event->payload = $payload;
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

    private function orderingConflict(): HttpResponseException
    {
        return new HttpResponseException(ApiResponse::error(
            ApiErrorCode::CONFLICT,
            'Assessment ordering conflict. Retry the request.',
            409,
        ));
    }
}
