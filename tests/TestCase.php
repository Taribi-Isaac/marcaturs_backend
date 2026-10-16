<?php

namespace Tests;

use App\Models\User;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Authenticate as a first-party browser session (Sanctum TransientToken).
     *
     * Admin API routes reject Bearer PATs (MH-SEC-012). Feature tests that exercise
     * Admin endpoints must use this helper rather than Sanctum::actingAs(), which
     * attaches a mock personal access token and is rejected by EnsureAdminOrigin.
     *
     * Flushes the session/guards first so prior participant actingAs() calls in the
     * same test cannot leak into the Admin request.
     */
    protected function actingAsBrowserSession(User $user): static
    {
        $this->app['auth']->forgetGuards();

        if ($this->app->bound('session')) {
            $this->app['session']->flush();
        }

        return $this->actingAs($user, 'web');
    }
}
