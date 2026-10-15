<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use App\Support\Api\ApiErrorCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as RouteFacade;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminOriginBindingTest extends TestCase
{
    use RefreshDatabase;

    private const ADMIN_ORIGIN = 'https://admin.marcaturshub.com.ng';

    private const APP_ORIGIN = 'https://app.marcaturshub.com.ng';

    protected function setUp(): void
    {
        parent::setUp();

        config(['security.admin_origins' => [self::ADMIN_ORIGIN]]);
    }

    public function test_cookie_authenticated_admin_request_from_admin_origin_is_accepted(): void
    {
        $this->actingAs(User::factory()->admin()->create(), 'web');

        $this->withHeader('Origin', self::ADMIN_ORIGIN)
            ->getJson('/api/v1/admin/overview')
            ->assertOk();
    }

    public function test_cookie_authenticated_admin_request_from_participant_origin_is_rejected(): void
    {
        $this->actingAs(User::factory()->admin()->create(), 'web');

        $this->withHeader('Origin', self::APP_ORIGIN)
            ->getJson('/api/v1/admin/overview')
            ->assertStatus(403)
            ->assertJsonPath('error.code', ApiErrorCode::FORBIDDEN);

        $this->withHeader('Origin', self::APP_ORIGIN)
            ->getJson('/api/v1/admin/users')
            ->assertStatus(403);
    }

    public function test_cookie_authenticated_admin_request_without_or_with_lookalike_origin_is_rejected(): void
    {
        $this->actingAs(User::factory()->admin()->create(), 'web');

        $this->getJson('/api/v1/admin/overview')->assertStatus(403);

        foreach (['null', 'https://admin.marcaturshub.com.ng.evil.example', 'http://admin.marcaturshub.com.ng', 'https://evil.example'] as $origin) {
            $this->withHeader('Origin', $origin)
                ->getJson('/api/v1/admin/overview')
                ->assertStatus(403);
        }
    }

    public function test_origin_binding_applies_to_admin_writes(): void
    {
        $this->actingAs(User::factory()->admin()->create(), 'web');

        $this->withHeader('Origin', self::APP_ORIGIN)
            ->postJson('/api/v1/admin/categories', ['name' => 'Should not be created'])
            ->assertStatus(403);

        $this->assertDatabaseMissing('categories', ['name' => 'Should not be created']);
    }

    public function test_participant_routes_are_not_origin_bound(): void
    {
        $this->actingAs(User::factory()->business()->create(), 'web');

        $this->withHeader('Origin', self::APP_ORIGIN)
            ->getJson('/api/v1/auth/me')
            ->assertOk();
    }

    public function test_non_admin_cookie_user_is_still_rejected_by_role_from_admin_origin(): void
    {
        $this->actingAs(User::factory()->business()->create(), 'web');

        $this->withHeader('Origin', self::ADMIN_ORIGIN)
            ->getJson('/api/v1/admin/overview')
            ->assertStatus(403);
    }

    public function test_token_authenticated_admin_request_from_a_browser_origin_is_origin_bound(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());

        foreach ([self::APP_ORIGIN, 'null', 'https://evil.example'] as $origin) {
            $this->withHeader('Origin', $origin)
                ->getJson('/api/v1/admin/overview')
                ->assertStatus(403)
                ->assertJsonPath('error.code', ApiErrorCode::FORBIDDEN);
        }

        $this->withHeader('Origin', self::ADMIN_ORIGIN)
            ->getJson('/api/v1/admin/overview')
            ->assertOk();
    }

    public function test_token_authenticated_admin_request_without_origin_is_accepted(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->getJson('/api/v1/admin/overview')->assertOk();
    }

    public function test_production_without_configured_admin_origin_fails_closed(): void
    {
        config(['security.admin_origins' => []]);
        $this->app['env'] = 'production';

        $this->actingAs(User::factory()->admin()->create(), 'web');

        $this->withHeader('Origin', self::ADMIN_ORIGIN)
            ->getJson('/api/v1/admin/overview')
            ->assertStatus(403);
    }

    public function test_local_without_configured_admin_origin_is_not_enforced(): void
    {
        config(['security.admin_origins' => []]);

        $this->actingAs(User::factory()->admin()->create(), 'web');

        $this->withHeader('Origin', 'http://localhost:5174')
            ->getJson('/api/v1/admin/overview')
            ->assertOk();
    }

    public function test_every_admin_route_is_origin_bound(): void
    {
        $adminRoutes = collect(RouteFacade::getRoutes()->getRoutes())
            ->filter(fn (Route $route) => str_starts_with($route->uri(), 'api/v1/admin'));

        $this->assertNotEmpty($adminRoutes);

        $adminRoutes->each(function (Route $route): void {
            $this->assertContains(
                'admin.origin',
                $route->gatherMiddleware(),
                "Route {$route->uri()} is missing the admin.origin middleware.",
            );
        });
    }
}
