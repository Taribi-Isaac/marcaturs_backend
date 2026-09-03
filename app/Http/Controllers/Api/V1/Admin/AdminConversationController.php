<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\ConversationResource;
use App\Http\Resources\Api\V1\MessageResource;
use App\Models\Conversation;
use App\Services\Conversations\ConversationService;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminConversationController extends Controller
{
    public function __construct(
        private readonly ConversationService $conversations,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $paginator = $this->conversations->listReportedForAdmin(
            $request->user(),
            $this->perPage($request),
        );

        return ApiResponse::paginated(
            $paginator,
            ConversationResource::collection($paginator->getCollection())->resolve($request),
        );
    }

    public function show(Request $request, Conversation $conversation): JsonResponse
    {
        $found = $this->conversations->showReportedForAdmin($request->user(), $conversation);

        return ApiResponse::success(
            (new ConversationResource($found))->resolve($request),
        );
    }

    public function messages(Request $request, Conversation $conversation): JsonResponse
    {
        $paginator = $this->conversations->listReportedMessagesForAdmin(
            $request->user(),
            $conversation,
            $this->perPage($request),
        );

        return ApiResponse::paginated(
            $paginator,
            MessageResource::collection($paginator->getCollection())->resolve($request),
        );
    }

    private function perPage(Request $request): int
    {
        return min(
            max(1, (int) $request->input('per_page', config('api.pagination.default_per_page'))),
            (int) config('api.pagination.max_per_page'),
        );
    }
}
