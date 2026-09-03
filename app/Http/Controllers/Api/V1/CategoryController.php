<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\CategoryResource;
use App\Services\Categories\CategoryAdminService;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;

class CategoryController extends Controller
{
    public function __construct(
        private readonly CategoryAdminService $categories,
    ) {}

    public function index(): JsonResponse
    {
        return ApiResponse::success(
            CategoryResource::collection($this->categories->visibleToParticipants())->resolve(),
        );
    }
}
