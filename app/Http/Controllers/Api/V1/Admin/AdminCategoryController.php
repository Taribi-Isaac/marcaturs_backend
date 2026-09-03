<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\Categories\StoreCategoryRequest;
use App\Http\Requests\Api\V1\Admin\Categories\UpdateCategoryRequest;
use App\Http\Resources\Api\V1\AdminCategoryResource;
use App\Models\Category;
use App\Services\Categories\CategoryAdminService;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;

class AdminCategoryController extends Controller
{
    public function __construct(
        private readonly CategoryAdminService $categories,
    ) {}

    public function index(): JsonResponse
    {
        return ApiResponse::success(
            AdminCategoryResource::collection($this->categories->all())->resolve(),
        );
    }

    public function store(StoreCategoryRequest $request): JsonResponse
    {
        $category = $this->categories->create($request->validated());

        return ApiResponse::success(
            (new AdminCategoryResource($category))->resolve($request),
            201,
        );
    }

    public function update(UpdateCategoryRequest $request, Category $category): JsonResponse
    {
        $updated = $this->categories->update($category, $request->validated());

        return ApiResponse::success(
            (new AdminCategoryResource($updated))->resolve($request),
        );
    }
}
