<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\Certification\ReorderCertificationQuestionsRequest;
use App\Http\Requests\Api\V1\Admin\Certification\StoreCertificationAssessmentRequest;
use App\Http\Requests\Api\V1\Admin\Certification\StoreCertificationQuestionRequest;
use App\Http\Requests\Api\V1\Admin\Certification\UpdateCertificationAssessmentRequest;
use App\Http\Requests\Api\V1\Admin\Certification\UpdateCertificationQuestionRequest;
use App\Http\Resources\Api\V1\CertificationAssessmentResource;
use App\Http\Resources\Api\V1\CertificationQuestionResource;
use App\Models\CertificationProgramme;
use App\Models\CertificationProgrammeVersion;
use App\Models\CertificationQuestion;
use App\Services\Certification\CertificationAssessmentAdminService;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminCertificationAssessmentController extends Controller
{
    public function __construct(
        private readonly CertificationAssessmentAdminService $assessments,
    ) {}

    public function show(
        Request $request,
        CertificationProgramme $programme,
        CertificationProgrammeVersion $version,
    ): JsonResponse {
        $assessment = $this->assessments->show($request->user(), $programme, $version);

        return ApiResponse::success(
            (new CertificationAssessmentResource($assessment, admin: true))->resolve($request),
        );
    }

    public function store(
        StoreCertificationAssessmentRequest $request,
        CertificationProgramme $programme,
        CertificationProgrammeVersion $version,
    ): JsonResponse {
        $assessment = $this->assessments->create(
            $request->user(),
            $programme,
            $version,
            $request->validated(),
        );

        return ApiResponse::success(
            (new CertificationAssessmentResource($assessment, admin: true))->resolve($request),
            201,
        );
    }

    public function update(
        UpdateCertificationAssessmentRequest $request,
        CertificationProgramme $programme,
        CertificationProgrammeVersion $version,
    ): JsonResponse {
        $assessment = $this->assessments->update(
            $request->user(),
            $programme,
            $version,
            $request->validated(),
        );

        return ApiResponse::success(
            (new CertificationAssessmentResource($assessment, admin: true))->resolve($request),
        );
    }

    public function indexQuestions(
        Request $request,
        CertificationProgramme $programme,
        CertificationProgrammeVersion $version,
    ): JsonResponse {
        $questions = $this->assessments->indexQuestions($request->user(), $programme, $version);

        return ApiResponse::success(
            $questions->map(
                fn ($question) => (new CertificationQuestionResource($question, admin: true))->resolve($request),
            )->values()->all(),
        );
    }

    public function showQuestion(
        Request $request,
        CertificationProgramme $programme,
        CertificationProgrammeVersion $version,
        CertificationQuestion $question,
    ): JsonResponse {
        $question = $this->assessments->showQuestion($request->user(), $programme, $version, $question);

        return ApiResponse::success(
            (new CertificationQuestionResource($question, admin: true))->resolve($request),
        );
    }

    public function storeQuestion(
        StoreCertificationQuestionRequest $request,
        CertificationProgramme $programme,
        CertificationProgrammeVersion $version,
    ): JsonResponse {
        $question = $this->assessments->createQuestion(
            $request->user(),
            $programme,
            $version,
            $request->validated(),
        );

        return ApiResponse::success(
            (new CertificationQuestionResource($question, admin: true))->resolve($request),
            201,
        );
    }

    public function updateQuestion(
        UpdateCertificationQuestionRequest $request,
        CertificationProgramme $programme,
        CertificationProgrammeVersion $version,
        CertificationQuestion $question,
    ): JsonResponse {
        $updated = $this->assessments->updateQuestion(
            $request->user(),
            $programme,
            $version,
            $question,
            $request->validated(),
        );

        return ApiResponse::success(
            (new CertificationQuestionResource($updated, admin: true))->resolve($request),
        );
    }

    public function destroyQuestion(
        Request $request,
        CertificationProgramme $programme,
        CertificationProgrammeVersion $version,
        CertificationQuestion $question,
    ): JsonResponse {
        $this->assessments->deleteQuestion($request->user(), $programme, $version, $question);

        return ApiResponse::success(['deleted' => true]);
    }

    public function reorderQuestions(
        ReorderCertificationQuestionsRequest $request,
        CertificationProgramme $programme,
        CertificationProgrammeVersion $version,
    ): JsonResponse {
        $questions = $this->assessments->reorderQuestions(
            $request->user(),
            $programme,
            $version,
            $request->validated(),
        );

        return ApiResponse::success(
            $questions->map(
                fn ($question) => (new CertificationQuestionResource($question, admin: true))->resolve($request),
            )->values()->all(),
        );
    }
}
