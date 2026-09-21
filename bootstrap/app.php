<?php

use App\Http\Middleware\EnsureAccountAccess;
use App\Http\Middleware\EnsureAdminPermission;
use App\Http\Middleware\EnsureEmailVerified;
use App\Http\Middleware\EnsureUserHasRole;
use App\Http\Middleware\SetSecurityHeaders;
use App\Support\Api\ApiExceptionRenderer;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        apiPrefix: 'api',
    )
    ->withBroadcasting(
        __DIR__.'/../routes/channels.php',
        [
            'prefix' => 'api',
            'middleware' => ['api', 'auth:sanctum', 'account.access', 'email.verified'],
        ],
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->trustProxies(at: '*');
        $middleware->redirectGuestsTo(function (Request $request): ?string {
            return $request->is('api/*') ? null : '/login';
        });
        $middleware->statefulApi();
        $middleware->throttleApi('api');
        $middleware->append(SetSecurityHeaders::class);
        $middleware->alias([
            'account.access' => EnsureAccountAccess::class,
            'email.verified' => EnsureEmailVerified::class,
            'role' => EnsureUserHasRole::class,
            'permission' => EnsureAdminPermission::class,
        ]);
        $middleware->preventRequestsDuringMaintenance(except: [
            'api/v1/health',
            'api/v1/webhooks/paystack',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        $exceptions->dontFlash([
            'current_password',
            'password',
            'password_confirmation',
            'token',
            'secret',
        ]);

        $exceptions->map(
            ValidationException::class,
            fn (ValidationException $exception) => $exception->status(400),
        );

        $exceptions->render(function (Throwable $e, Request $request) {
            return app(ApiExceptionRenderer::class)->render($e, $request);
        });
    })
    ->withSchedule(function (Schedule $schedule): void {
        $schedule->command('campaigns:process-lifecycle')->everyFifteenMinutes();
        $schedule->command('commissions:process-overdue')->everyFifteenMinutes();
        $schedule->command('commissions:process-reminders')->everyFifteenMinutes();
    })->create();
