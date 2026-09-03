<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Conversations\ReportConversationRequest;
use App\Http\Requests\Api\V1\Conversations\StoreConversationRequest;
use App\Http\Requests\Api\V1\Conversations\StoreMessageRequest;
use App\Http\Resources\Api\V1\ConversationResource;
use App\Http\Resources\Api\V1\MessageResource;
use App\Models\Conversation;
use App\Services\Conversations\ConversationService;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ConversationController extends Controller
{
    public function __construct(
        private readonly ConversationService $conversations,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $paginator = $this->conversations->listForParticipant(
            $request->user(),
            $this->perPage($request),
        );

        return ApiResponse::paginated(
            $paginator,
            ConversationResource::collection($paginator->getCollection())->resolve($request),
        );
    }

    public function store(StoreConversationRequest $request): JsonResponse
    {
        [$conversation, $created] = $this->conversations->open(
            $request->user(),
            $request->validated(),
        );

        return ApiResponse::success(
            (new ConversationResource($conversation))->resolve($request),
            $created ? 201 : 200,
        );
    }

    public function show(Request $request, Conversation $conversation): JsonResponse
    {
        $found = $this->conversations->showForParticipant($request->user(), $conversation);

        return ApiResponse::success(
            (new ConversationResource($found))->resolve($request),
        );
    }

    public function messages(Request $request, Conversation $conversation): JsonResponse
    {
        $paginator = $this->conversations->listMessagesForParticipant(
            $request->user(),
            $conversation,
            $this->perPage($request),
        );

        return ApiResponse::paginated(
            $paginator,
            MessageResource::collection($paginator->getCollection())->resolve($request),
        );
    }

    public function send(StoreMessageRequest $request, Conversation $conversation): JsonResponse
    {
        $message = $this->conversations->send(
            $request->user(),
            $conversation,
            (string) $request->validated()['content'],
        );

        return ApiResponse::success(
            (new MessageResource($message))->resolve($request),
            201,
        );
    }

    public function markRead(Request $request, Conversation $conversation): JsonResponse
    {
        $updated = $this->conversations->markRead($request->user(), $conversation);

        return ApiResponse::success(['updated' => $updated]);
    }

    public function report(ReportConversationRequest $request, Conversation $conversation): JsonResponse
    {
        $reported = $this->conversations->report(
            $request->user(),
            $conversation,
            (string) $request->validated('reason'),
        );

        return ApiResponse::success(
            (new ConversationResource($reported))->resolve($request),
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
