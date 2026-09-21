<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\Verification\ApproveVerificationSubmissionRequest;
use App\Http\Requests\Api\V1\Admin\Verification\ReviewVerificationSubmissionRequest;
use App\Http\Requests\Api\V1\Admin\Verification\StoreVerificationRequirementRequest;
use App\Http\Requests\Api\V1\Admin\Verification\UpdateVerificationRequirementRequest;
use App\Http\Resources\Api\V1\AdminVerificationRequirementResource;
use App\Http\Resources\Api\V1\AdminVerificationSubmissionResource;
use App\Http\Resources\Api\V1\VerificationReviewEventResource;
use App\Models\VerificationEvidence;
use App\Models\VerificationRequirement;
use App\Models\VerificationSubmission;
use App\Services\Verification\VerificationEvidenceStore;
use App\Services\Verification\VerificationRequirementAdminService;
use App\Services\Verification\VerificationSubmissionService;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AdminVerificationController extends Controller
{
    public function __construct(
        private readonly VerificationRequirementAdminService $requirements,
        private readonly VerificationSubmissionService $submissions,
        private readonly VerificationEvidenceStore $evidence,
    ) {}

    public function indexRequirements(): JsonResponse
    {
        $items = VerificationRequirement::query()->orderBy('sort_order')->orderBy('id')->get();

        return ApiResponse::success(
            AdminVerificationRequirementResource::collection($items)->resolve(),
        );
    }

    public function storeRequirement(StoreVerificationRequirementRequest $request): JsonResponse
    {
        $requirement = $this->requirements->create($request->user(), $request->validated());

        return ApiResponse::success(
            (new AdminVerificationRequirementResource($requirement))->resolve($request),
            201,
        );
    }

    public function updateRequirement(UpdateVerificationRequirementRequest $request, VerificationRequirement $requirement): JsonResponse
    {
        $updated = $this->requirements->update($request->user(), $requirement, $request->validated());

        return ApiResponse::success(
            (new AdminVerificationRequirementResource($updated))->resolve($request),
        );
    }

    public function indexSubmissions(Request $request): JsonResponse
    {
        $query = VerificationSubmission::query()
            ->with(['requirement', 'evidence', 'user'])
            ->latest('submitted_at');

        if ($request->filled('status')) {
            $query->where('status', $request->string('status')->toString());
        }

        $paginator = $query->paginate();

        return ApiResponse::paginated(
            $paginator,
            AdminVerificationSubmissionResource::collection($paginator->getCollection())->resolve($request),
        );
    }

    public function showSubmission(VerificationSubmission $submission): JsonResponse
    {
        $submission->load(['requirement', 'evidence', 'user', 'reviewEvents']);

        return ApiResponse::success(
            (new AdminVerificationSubmissionResource($submission))->resolve(),
        );
    }

    public function startReview(Request $request, VerificationSubmission $submission): JsonResponse
    {
        $updated = $this->submissions->startReview($request->user(), $submission);

        return ApiResponse::success(
            (new AdminVerificationSubmissionResource($updated))->resolve($request),
        );
    }

    public function approve(ApproveVerificationSubmissionRequest $request, VerificationSubmission $submission): JsonResponse
    {
        $updated = $this->submissions->approve($request->user(), $submission, $request->input('notes'));

        return ApiResponse::success(
            (new AdminVerificationSubmissionResource($updated))->resolve($request),
        );
    }

    public function reject(ReviewVerificationSubmissionRequest $request, VerificationSubmission $submission): JsonResponse
    {
        $updated = $this->submissions->reject(
            $request->user(),
            $submission,
            $request->string('reason')->toString(),
            $request->input('notes'),
        );

        return ApiResponse::success(
            (new AdminVerificationSubmissionResource($updated))->resolve($request),
        );
    }

    public function requestInformation(ReviewVerificationSubmissionRequest $request, VerificationSubmission $submission): JsonResponse
    {
        $updated = $this->submissions->requestInformation(
            $request->user(),
            $submission,
            $request->string('reason')->toString(),
            $request->input('notes'),
        );

        return ApiResponse::success(
            (new AdminVerificationSubmissionResource($updated))->resolve($request),
        );
    }

    public function events(VerificationSubmission $submission): JsonResponse
    {
        $events = $submission->reviewEvents()->orderBy('id')->get();

        return ApiResponse::success(
            VerificationReviewEventResource::collection($events)->resolve(),
        );
    }

    public function downloadEvidence(VerificationSubmission $submission, VerificationEvidence $evidence): Response
    {
        if ($evidence->verification_submission_id !== $submission->id) {
            abort(404);
        }

        return $this->evidence->stream($evidence);
    }
}
