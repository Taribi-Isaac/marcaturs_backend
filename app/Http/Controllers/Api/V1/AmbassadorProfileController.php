<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Ambassador\StoreAmbassadorProfileRequest;
use App\Http\Requests\Api\V1\Ambassador\UpdateAmbassadorProfileRequest;
use App\Http\Resources\Api\V1\AmbassadorProfileResource;
use App\Services\Profiles\AmbassadorProfileService;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AmbassadorProfileController extends Controller
{
    public function __construct(private readonly AmbassadorProfileService $profiles) {}

    public function show(Request $request): JsonResponse
    {
        return ApiResponse::success(
            (new AmbassadorProfileResource($this->profiles->show($request->user())))->resolve($request),
        );
    }

    public function store(StoreAmbassadorProfileRequest $request): JsonResponse
    {
        $profile = $this->profiles->create($request->user(), $request->validated());

        return ApiResponse::success(
            (new AmbassadorProfileResource($profile))->resolve($request),
            201,
        );
    }

    public function update(UpdateAmbassadorProfileRequest $request): JsonResponse
    {
        $profile = $this->profiles->update($request->user(), $request->validated());

        return ApiResponse::success(
            (new AmbassadorProfileResource($profile))->resolve($request),
        );
    }
}
