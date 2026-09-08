<?php

namespace App\Providers;

use App\Contracts\Payments\PlatformPaymentGateway;
use App\Enums\Role;
use App\Models\User;
use App\Services\Payments\PaystackGateway;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(PlatformPaymentGateway::class, PaystackGateway::class);
    }

    public function boot(): void
    {
        JsonResource::withoutWrapping();

        Password::defaults(static fn () => Password::min(8));

        Gate::define('admin', static fn (User $user): bool => $user->hasRole(Role::Admin));
        Gate::define('business', static fn (User $user): bool => $user->hasRole(Role::Business));
        Gate::define('ambassador', static fn (User $user): bool => $user->hasRole(Role::Ambassador));

        if ($this->app->environment('production', 'staging')) {
            URL::forceScheme('https');
        }

        $this->registerRateLimiters();
    }

    private function registerRateLimiters(): void
    {
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute((int) config('api.rate_limits.api_per_minute'))
                ->by($request->user()?->getAuthIdentifier() ?: $request->ip());
        });

        RateLimiter::for('login', function (Request $request) {
            return Limit::perMinute((int) config('api.rate_limits.login_per_minute'))
                ->by($request->ip());
        });

        RateLimiter::for('registration', function (Request $request) {
            return Limit::perMinute((int) config('api.rate_limits.registration_per_minute'))
                ->by($request->ip());
        });

        RateLimiter::for('password-reset', function (Request $request) {
            return Limit::perMinute((int) config('api.rate_limits.password_reset_per_minute'))
                ->by($request->ip());
        });

        RateLimiter::for('change-password', function (Request $request) {
            return Limit::perMinute((int) config('api.rate_limits.change_password_per_minute'))
                ->by($request->user()?->getAuthIdentifier() ?: $request->ip());
        });

        RateLimiter::for('email-verification', function (Request $request) {
            return Limit::perMinute((int) config('api.rate_limits.email_verification_per_minute'))
                ->by($request->user()?->getAuthIdentifier() ?: $request->ip());
        });

        RateLimiter::for('uploads', function (Request $request) {
            return Limit::perMinute((int) config('api.rate_limits.uploads_per_minute'))
                ->by($request->user()?->getAuthIdentifier() ?: $request->ip());
        });
    }
}
