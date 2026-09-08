<?php

namespace App\Services\Auth;

use App\Enums\AccountStatus;
use App\Enums\Role;
use App\Models\User;
use App\Support\Api\ApiErrorCode;
use App\Support\Api\ApiResponse;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;

class AuthenticationService
{
    /**
     * @return array{user: User, token: string}
     */
    public function register(string $name, string $email, string $password, Role $role): array
    {
        $user = new User;
        $user->name = $name;
        $user->email = $email;
        $user->password = $password;
        $user->role = $role;
        $user->status = AccountStatus::Active;
        $user->save();

        $user->sendEmailVerificationNotification();

        return $this->establishSession($user);
    }

    /**
     * @return array{user: User, token: string}
     */
    public function login(string $email, string $password): array
    {
        $user = User::query()->where('email', $email)->first();

        if ($user === null || ! Hash::check($password, $user->password)) {
            $this->rejectInvalidCredentials();
        }

        if (! $user->status->canAuthenticate()) {
            throw new AuthorizationException('This account is not permitted to access the platform.');
        }

        return $this->establishSession($user);
    }

    public function logout(Request $request): void
    {
        $user = $request->user();

        if ($user instanceof User) {
            $user->tokens()->delete();
        }

        Auth::guard('web')->logout();
        Auth::forgetGuards();

        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }
    }

    /**
     * Change the authenticated user's password.
     *
     * Keeps the current Sanctum personal access token (and web session when present)
     * so Admin Control UX is not forced to re-login. Revokes other personal access
     * tokens so previously issued credentials are no longer trusted.
     */
    public function changePassword(User $user, string $password, Request $request): void
    {
        $user->forceFill([
            'password' => $password,
            'remember_token' => Str::random(60),
        ])->save();

        $currentToken = $user->currentAccessToken();

        if ($currentToken instanceof PersonalAccessToken) {
            $user->tokens()->whereKeyNot($currentToken->getKey())->delete();
        } else {
            $user->tokens()->delete();
        }

        if ($request->hasSession()) {
            $request->session()->regenerate();
        }
    }

    /**
     * @return array{user: User, token: string}
     */
    private function establishSession(User $user): array
    {
        if (request()->hasSession()) {
            Auth::guard('web')->login($user);
            request()->session()->regenerate();
        }

        $user->forceFill([
            'last_login_at' => now(),
        ])->save();

        return [
            'user' => $user->refresh(),
            'token' => $user->createToken('auth')->plainTextToken,
        ];
    }

    private function rejectInvalidCredentials(): never
    {
        throw new HttpResponseException(ApiResponse::error(
            ApiErrorCode::UNAUTHENTICATED,
            'Invalid credentials.',
            401,
        ));
    }
}
