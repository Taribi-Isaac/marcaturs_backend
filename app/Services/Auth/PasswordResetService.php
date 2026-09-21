<?php

namespace App\Services\Auth;

use App\Models\User;
use App\Support\Api\ApiErrorCode;
use App\Support\Api\ApiResponse;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class PasswordResetService
{
    /**
     * Always returns the same client-facing outcome so callers cannot enumerate
     * whether an email is registered (or whether broker throttling applied).
     */
    public function sendResetLink(string $email): void
    {
        Password::broker()->sendResetLink([
            'email' => $email,
        ]);
    }

    public function reset(string $email, string $token, string $password): User
    {
        $status = Password::broker()->reset(
            [
                'email' => $email,
                'token' => $token,
                'password' => $password,
            ],
            function (User $user, string $password): void {
                $user->forceFill([
                    'password' => $password,
                    'remember_token' => Str::random(60),
                ])->save();

                $user->tokens()->delete();
                $this->invalidateDatabaseSessions($user);

                event(new PasswordReset($user));
            },
        );

        if ($status !== Password::PASSWORD_RESET) {
            throw new HttpResponseException(ApiResponse::error(
                ApiErrorCode::BUSINESS_VALIDATION,
                'This password reset token is invalid.',
                422,
            ));
        }

        /** @var User $user */
        $user = User::query()->where('email', $email)->firstOrFail();

        return $user;
    }

    /**
     * MH-GATE-008 / MH-BE-047: password reset must invalidate persisted browser sessions.
     */
    private function invalidateDatabaseSessions(User $user): void
    {
        if (! Schema::hasTable('sessions')) {
            return;
        }

        DB::table('sessions')->where('user_id', $user->id)->delete();
    }
}
