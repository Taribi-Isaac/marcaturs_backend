<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Auth\ForgotPasswordRequest;
use App\Http\Requests\Api\V1\Auth\LoginRequest;
use App\Http\Requests\Api\V1\Auth\RegisterRequest;
use App\Http\Requests\Api\V1\Auth\ResetPasswordRequest;
use App\Http\Resources\Api\V1\UserResource;
use App\Models\User;
use App\Services\Auth\AuthenticationService;
use App\Services\Auth\EmailVerificationService;
use App\Services\Auth\PasswordResetService;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuthController extends Controller
{
    public function __construct(
        private readonly AuthenticationService $authentication,
        private readonly PasswordResetService $passwordReset,
        private readonly EmailVerificationService $emailVerification,
    ) {}

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

    public function forgotPassword(ForgotPasswordRequest $request): JsonResponse
    {
        $this->passwordReset->sendResetLink(
            $request->string('email')->toString(),
        );

        return ApiResponse::success([
            'message' => 'If that email address is registered, a password reset link has been sent.',
        ]);
    }

    public function resetPassword(ResetPasswordRequest $request): JsonResponse
    {
        $user = $this->passwordReset->reset(
            $request->string('email')->toString(),
            $request->string('token')->toString(),
            $request->string('password')->toString(),
        );

        return ApiResponse::success([
            'message' => 'Your password has been reset.',
            'user' => (new UserResource($user))->resolve($request),
        ]);
    }

    public function verifyEmail(Request $request, int $id, string $hash): JsonResponse
    {
        /** @var User $user */
        $user = User::query()->findOrFail($id);

        $user = $this->emailVerification->verify($user, $hash);

        return ApiResponse::success([
            'message' => 'Email verified successfully.',
            'user' => (new UserResource($user))->resolve($request),
        ]);
    }

    public function resendEmailVerification(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $result = $this->emailVerification->resend($user);

        if ($result['already_verified']) {
            return ApiResponse::success([
                'message' => 'Email address is already verified.',
                'already_verified' => true,
            ]);
        }

        return ApiResponse::success([
            'message' => 'A new verification link has been sent.',
            'already_verified' => false,
        ]);
    }
}
