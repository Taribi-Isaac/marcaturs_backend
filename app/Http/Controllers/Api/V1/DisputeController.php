<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Disputes\StoreDisputeAttachmentRequest;
use App\Http\Requests\Api\V1\Disputes\StoreDisputeRequest;
use App\Http\Resources\Api\V1\DisputeAttachmentResource;
use App\Http\Resources\Api\V1\DisputeCategoryResource;
use App\Http\Resources\Api\V1\DisputeResource;
use App\Models\Deal;
use App\Models\Dispute;
use App\Models\DisputeAttachment;
use App\Services\Disputes\DisputeCategoryService;
use App\Services\Disputes\DisputeService;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DisputeController extends Controller
{
    public function __construct(
        private readonly DisputeService $disputes,
        private readonly DisputeCategoryService $categories,
    ) {}

    public function categories(): JsonResponse
    {
        return ApiResponse::success(
            DisputeCategoryResource::collection($this->categories->listActive())->resolve(),
        );
    }

    public function store(StoreDisputeRequest $request, Deal $deal): JsonResponse
    {
        $created = $this->disputes->create($request->user(), $deal, $request->validated());

        return ApiResponse::success(
            (new DisputeResource($created))->resolve($request),
            201,
        );
    }

    public function index(Request $request): JsonResponse
    {
        $paginator = $this->disputes->listForParticipant($request->user());

        return ApiResponse::paginated(
            $paginator,
            DisputeResource::collection($paginator->getCollection())->resolve($request),
        );
    }

    public function show(Request $request, Dispute $dispute): JsonResponse
    {
        $found = $this->disputes->showForParticipant($request->user(), $dispute);

        return ApiResponse::success(
            (new DisputeResource($found))->resolve($request),
        );
    }

    public function storeAttachment(
        StoreDisputeAttachmentRequest $request,
        Dispute $dispute,
    ): JsonResponse {
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
