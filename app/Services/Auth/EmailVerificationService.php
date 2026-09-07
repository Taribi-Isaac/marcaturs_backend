<?php

namespace App\Services\Auth;

use App\Models\User;
use App\Support\Api\ApiErrorCode;
use App\Support\Api\ApiResponse;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\Exceptions\HttpResponseException;

class EmailVerificationService
{
    public function verify(User $user, string $hash): User
    {
        if (! hash_equals(sha1($user->getEmailForVerification()), $hash)) {
            throw new HttpResponseException(ApiResponse::error(
                ApiErrorCode::FORBIDDEN,
                'This email verification link is invalid.',
                403,
            ));
        }

        if (! $user->hasVerifiedEmail()) {
            $user->markEmailAsVerified();
            event(new Verified($user));
        }

        return $user->refresh();
    }

    /**
     * @return array{sent: bool, already_verified: bool}
     */
    public function resend(User $user): array
    {
        if ($user->hasVerifiedEmail()) {
            return [
                'sent' => false,
                'already_verified' => true,
            ];
        }

        $user->sendEmailVerificationNotification();

        return [
            'sent' => true,
            'already_verified' => false,
        ];
    }
}
