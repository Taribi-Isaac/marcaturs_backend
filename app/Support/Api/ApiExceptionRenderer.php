<?php

namespace App\Support\Api;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Exceptions\InvalidSignatureException;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Throwable;

final class ApiExceptionRenderer
{
    public function render(Throwable $exception, Request $request): ?JsonResponse
    {
        if (! $this->shouldRenderAsApi($request)) {
            return null;
        }

        return match (true) {
            $exception instanceof ValidationException => ApiResponse::validation($exception->validator),
            $exception instanceof AuthenticationException => ApiResponse::error(
                ApiErrorCode::UNAUTHENTICATED,
                'Authentication is required.',
                401,
            ),
            $exception instanceof AuthorizationException => ApiResponse::error(
                ApiErrorCode::FORBIDDEN,
                $exception->getMessage() !== '' ? $exception->getMessage() : 'You are not authorized to perform this action.',
                403,
            ),
            $exception instanceof InvalidSignatureException => ApiResponse::error(
                ApiErrorCode::FORBIDDEN,
                'This email verification link is invalid or has expired.',
                403,
            ),
            $exception instanceof ModelNotFoundException,
            $exception instanceof NotFoundHttpException => ApiResponse::error(
                ApiErrorCode::NOT_FOUND,
                'The requested resource was not found.',
                404,
            ),
            $exception instanceof TooManyRequestsHttpException => ApiResponse::error(
                ApiErrorCode::RATE_LIMITED,
                'Too many requests. Please try again later.',
                429,
            ),
            $exception instanceof HttpExceptionInterface => $this->httpException($exception),
            default => $this->serverError($exception),
        };
    }

    private function shouldRenderAsApi(Request $request): bool
    {
        return $request->is('api/*') || $request->expectsJson();
    }

    private function httpException(HttpExceptionInterface $exception): JsonResponse
    {
        $status = $exception->getStatusCode();

        [$code, $message] = match ($status) {
            400 => [ApiErrorCode::VALIDATION_ERROR, $exception->getMessage() ?: 'The request is invalid.'],
            401 => [ApiErrorCode::UNAUTHENTICATED, 'Authentication is required.'],
            403 => [ApiErrorCode::FORBIDDEN, $exception->getMessage() ?: 'You are not authorized to perform this action.'],
            404 => [ApiErrorCode::NOT_FOUND, 'The requested resource was not found.'],
            409 => [ApiErrorCode::CONFLICT, $exception->getMessage() ?: 'The request conflicts with the current resource state.'],
            422 => [ApiErrorCode::BUSINESS_VALIDATION, $exception->getMessage() ?: 'The request could not be processed.'],
            429 => [ApiErrorCode::RATE_LIMITED, 'Too many requests. Please try again later.'],
            503 => [ApiErrorCode::SERVICE_UNAVAILABLE, 'The service is temporarily unavailable.'],
            default => [ApiErrorCode::SERVER_ERROR, 'An unexpected error occurred.'],
        };

        if ($status >= 500 && $status !== 503) {
            return $this->serverError($exception);
        }

        return ApiResponse::error($code, $message, $status);
    }

    private function serverError(Throwable $exception): JsonResponse
    {
        $message = 'An unexpected error occurred.';

        if (config('app.debug')) {
            $message = $exception->getMessage() !== ''
                ? $exception->getMessage()
                : $message;
        }

        return ApiResponse::error(ApiErrorCode::SERVER_ERROR, $message, 500);
    }
}
