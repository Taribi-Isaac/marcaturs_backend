<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\NotificationResource;
use App\Support\Api\ApiErrorCode;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;

class NotificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $paginator = $request->user()
            ->notifications()
            ->latest()
            ->paginate($this->perPage($request));

        return ApiResponse::paginated(
            $paginator,
            NotificationResource::collection($paginator->getCollection())->resolve($request),
        );
    }

    public function show(Request $request, string $notification): JsonResponse
    {
        $record = $this->findOwnNotification($request, $notification);

        if ($record === null) {
            return ApiResponse::error(
                ApiErrorCode::NOT_FOUND,
                'Notification not found.',
                404,
            );
        }

        return ApiResponse::success(
            (new NotificationResource($record))->resolve($request),
        );
    }

    public function markRead(Request $request, string $notification): JsonResponse
    {
        $record = $this->findOwnNotification($request, $notification);

        if ($record === null) {
            return ApiResponse::error(
                ApiErrorCode::NOT_FOUND,
                'Notification not found.',
                404,
            );
        }

        if ($record->read_at === null) {
            $record->markAsRead();
        }

        return ApiResponse::success(
            (new NotificationResource($record->fresh()))->resolve($request),
        );
    }

    private function findOwnNotification(Request $request, string $id): ?DatabaseNotification
    {
        return $request->user()
            ->notifications()
            ->where('id', $id)
            ->first();
    }

    private function perPage(Request $request): int
    {
        return min(
            max(1, (int) $request->input('per_page', config('api.pagination.default_per_page'))),
            (int) config('api.pagination.max_per_page'),
        );
    }
}
