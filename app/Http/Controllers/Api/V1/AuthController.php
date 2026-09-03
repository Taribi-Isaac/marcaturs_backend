<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Auth\LoginRequest;
use App\Http\Requests\Api\V1\Auth\RegisterRequest;
use App\Http\Resources\Api\V1\UserResource;
use App\Services\Auth\AuthenticationService;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuthController extends Controller
{
    public function __construct(private readonly AuthenticationService $authentication) {}

    public function register(RegisterRequest $request): JsonResponse
    {
        $result = $this->authentication->register(
            $request->string('name')->toString(),
            $request->string('email')->toString(),
            $request->string('password')->toString(),
            Role::from($request->string('role')->toString()),
        );

        return ApiResponse::success([
            'user' => (new UserResource($result['user']))->resolve($request),
            'token' => $result['token'],
            'token_type' => 'Bearer',
        ], 201);
    }

    public function login(LoginRequest $request): JsonResponse
    {
        $result = $this->authentication->login(
            $request->string('email')->toString(),
            $request->string('password')->toString(),
        );

        return ApiResponse::success([
            'user' => (new UserResource($result['user']))->resolve($request),
            'token' => $result['token'],
            'token_type' => 'Bearer',
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $this->authentication->logout($request);

        return ApiResponse::success(null);
    }

    public function me(Request $request): JsonResponse
    {
        return ApiResponse::success(
            (new UserResource($request->user()))->resolve($request),
        );
    }
}
