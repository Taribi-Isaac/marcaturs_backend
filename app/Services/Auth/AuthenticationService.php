<?php

namespace App\Services\Auth;

use App\Enums\AccountStatus;
use App\Enums\Role;
use App\Models\User;
use App\Support\Api\ApiErrorCode;
use App\Support\Api\ApiResponse;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;

class AuthenticationService
{
    /**
     * @return array{user: User, token: ?string}
     */
    public function register(string $name, string $email, string $password, Role $role): array
    {
        $user = new User;
        $user->name = $name;
        $user->email = $email;
        $user->password = $password;
        $user->role = $role;
        $user->status = AccountStatus::Active;

        try {
            $user->save();
        } catch (UniqueConstraintViolationException) {
            // A concurrent request registered the same email after validation passed.
            throw ValidationException::withMessages([
                'email' => [__('validation.unique', ['attribute' => 'email'])],
            ]);
        }

        $user->sendEmailVerificationNotification();

        return $this->establishSession($user);
    }

    /**
     * @return array{user: User, token: ?string}
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

        // Do not regenerate the SPA session cookie here: concurrent authenticated
        // requests would race on the old session id and surface as 401 → client logout.
    }

    /**
     * Establish a web session for first-party SPA requests, or issue a single auth PAT
     * for non-browser clients (Business / Ambassador only).
     *
     * MH-GATE-008 / MH-BE-047: revoke all existing PATs on every login/register so
     * authentication PATs never accumulate. MH-SEC-001: Sanctum only starts a session
     * for requests from SANCTUM_STATEFUL_DOMAINS; those SPAs authenticate with the
     * session cookie, so they receive no Bearer token that script could copy.
     * MH-SEC-012: Admin accounts never receive a PAT — Admin API routes require a
     * browser session, and existing Admin PATs are rejected by EnsureAdminOrigin.
     *
     * @return array{user: User, token: ?string}
     */
    private function establishSession(User $user): array
    {
        $browserSession = request()->hasSession();

        if ($browserSession) {
            Auth::guard('web')->login($user);
            request()->session()->regenerate();
        }

        $plainTextToken = DB::transaction(function () use ($user, $browserSession): ?string {
            /** @var User $locked */
            $locked = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();

            $locked->tokens()->delete();

            $locked->forceFill([
                'last_login_at' => now(),
            ])->save();

            if ($browserSession || $locked->isAdmin()) {
                return null;
            }

            return $locked->createToken('auth')->plainTextToken;
        });

        return [
            'user' => $user->refresh(),
            'token' => $plainTextToken,
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
