<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        JsonResource::withoutWrapping();

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

        RateLimiter::for('uploads', function (Request $request) {
            return Limit::perMinute((int) config('api.rate_limits.uploads_per_minute'))
                ->by($request->user()?->getAuthIdentifier() ?: $request->ip());
        });
    }
}
