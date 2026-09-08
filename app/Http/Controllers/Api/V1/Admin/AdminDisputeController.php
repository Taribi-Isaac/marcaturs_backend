<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\DisputeStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\Disputes\AdminDisputeIndexRequest;
use App\Http\Requests\Api\V1\Admin\Disputes\AdminDisputeNoteRequest;
use App\Http\Requests\Api\V1\Admin\Disputes\AdminRequestEvidenceRequest;
use App\Http\Requests\Api\V1\Admin\Disputes\AdminResolveDisputeRequest;
use App\Http\Requests\Api\V1\Admin\Disputes\StoreDisputeCategoryRequest;
use App\Http\Requests\Api\V1\Admin\Disputes\UpdateDisputeCategoryRequest;
use App\Http\Requests\Api\V1\Disputes\StoreDisputeAttachmentRequest;
use App\Http\Resources\Api\V1\DisputeAttachmentResource;
use App\Http\Resources\Api\V1\DisputeCategoryResource;
use App\Http\Resources\Api\V1\DisputeResource;
use App\Models\Dispute;
use App\Models\DisputeAttachment;
use App\Models\DisputeCategory;
use App\Services\Disputes\DisputeCategoryService;
use App\Services\Disputes\DisputeService;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AdminDisputeController extends Controller
{
    public function __construct(
        private readonly DisputeService $disputes,
        private readonly DisputeCategoryService $categories,
    ) {}

    public function indexCategories(): JsonResponse
    {
        return ApiResponse::success(
            DisputeCategoryResource::collection($this->categories->listAll())->resolve(),
        );
    }

    public function storeCategory(StoreDisputeCategoryRequest $request): JsonResponse
    {
        $category = $this->categories->create($request->validated());

        return ApiResponse::success(
            (new DisputeCategoryResource($category))->resolve($request),
            201,
        );
    }

    public function updateCategory(UpdateDisputeCategoryRequest $request, DisputeCategory $disputeCategory): JsonResponse
    {
        $updated = $this->categories->update($disputeCategory, $request->validated());

        return ApiResponse::success(
            (new DisputeCategoryResource($updated))->resolve($request),
        );
    }

    public function index(AdminDisputeIndexRequest $request): JsonResponse
    {
        $statusValue = $request->validated('status');
        $status = is_string($statusValue) ? DisputeStatus::from($statusValue) : $statusValue;

        $paginator = $this->disputes->listForAdmin($status);

        return ApiResponse::paginated(
            $paginator,
            collect($paginator->items())->map(
                fn (Dispute $dispute) => (new DisputeResource($dispute, true))->resolve($request),
            )->all(),
        );
    }

    public function show(Request $request, Dispute $dispute): JsonResponse
    {
        $found = $this->disputes->showForAdmin($dispute);

        return ApiResponse::success(
            (new DisputeResource($found, true))->resolve($request),
        );
    }

    public function startReview(AdminDisputeNoteRequest $request, Dispute $dispute): JsonResponse
    {
        $updated = $this->disputes->startReview($request->user(), $dispute, $request->validated());

        return ApiResponse::success(
            (new DisputeResource($updated, true))->resolve($request),
        );
    }

    public function requestEvidence(AdminRequestEvidenceRequest $request, Dispute $dispute): JsonResponse
    {
        $updated = $this->disputes->requestEvidence($request->user(), $dispute, $request->validated());

        return ApiResponse::success(
            (new DisputeResource($updated, true))->resolve($request),
        );
    }

    public function resumeReview(AdminDisputeNoteRequest $request, Dispute $dispute): JsonResponse
    {
        $updated = $this->disputes->resumeReview($request->user(), $dispute, $request->validated());

        return ApiResponse::success(
            (new DisputeResource($updated, true))->resolve($request),
        );
    }

    public function markDecisionPending(AdminDisputeNoteRequest $request, Dispute $dispute): JsonResponse
    {
        $updated = $this->disputes->markDecisionPending($request->user(), $dispute, $request->validated());

        return ApiResponse::success(
            (new DisputeResource($updated, true))->resolve($request),
        );
    }

    public function resolve(AdminResolveDisputeRequest $request, Dispute $dispute): JsonResponse
    {
        $updated = $this->disputes->resolve($request->user(), $dispute, $request->validated());

        return ApiResponse::success(
            (new DisputeResource($updated, true))->resolve($request),
        );
    }

    public function close(AdminDisputeNoteRequest $request, Dispute $dispute): JsonResponse
    {
        $updated = $this->disputes->close($request->user(), $dispute, $request->validated());

        return ApiResponse::success(
            (new DisputeResource($updated, true))->resolve($request),
        );
    }

    public function storeAttachment(StoreDisputeAttachmentRequest $request, Dispute $dispute): JsonResponse
    {
        $attachment = $this->disputes->uploadAttachment(
            $request->user(),
            $dispute,
            $request->safe()->except('file'),
            $request->file('file'),
        );

        return ApiResponse::success(
            (new DisputeAttachmentResource($attachment))->resolve($request),
            201,
        );
    }

    public function downloadAttachment(
        Request $request,
        Dispute $dispute,
        DisputeAttachment $attachment,
    ): StreamedResponse {
        return $this->disputes->streamAttachment($request->user(), $dispute, $attachment);
    }
}
