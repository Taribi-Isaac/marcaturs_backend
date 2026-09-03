<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Business\StoreBusinessProfileRequest;
use App\Http\Requests\Api\V1\Business\UpdateBusinessProfileRequest;
use App\Http\Resources\Api\V1\BusinessProfileResource;
use App\Services\Profiles\BusinessProfileService;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BusinessProfileController extends Controller
{
    public function __construct(private readonly BusinessProfileService $profiles) {}

    public function show(Request $request): JsonResponse
    {
        return ApiResponse::success(
            (new BusinessProfileResource($this->profiles->show($request->user())))->resolve($request),
        );
    }

    public function store(StoreBusinessProfileRequest $request): JsonResponse
    {
        $profile = $this->profiles->create($request->user(), $request->validated());

        return ApiResponse::success(
            (new BusinessProfileResource($profile))->resolve($request),
            201,
        );
    }

    public function update(UpdateBusinessProfileRequest $request): JsonResponse
    {
        $profile = $this->profiles->update($request->user(), $request->validated());

        return ApiResponse::success(
            (new BusinessProfileResource($profile))->resolve($request),
        );
    }
}
