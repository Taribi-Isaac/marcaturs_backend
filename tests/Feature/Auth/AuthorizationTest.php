<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Support\Api\ApiErrorCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware(['api', 'auth:sanctum', 'account.access'])->prefix('api/v1')->group(function (): void {
            Route::get('/__auth/authenticated', fn () => ['ok' => true])->name('api.v1.__auth.authenticated');
            Route::get('/__auth/admin', fn () => ['ok' => true])->middleware('role:ADMIN')->name('api.v1.__auth.admin');
            Route::get('/__auth/business', fn () => ['ok' => true])->middleware('role:BUSINESS')->name('api.v1.__auth.business');
            Route::get('/__auth/ambassador', fn () => ['ok' => true])->middleware('role:AMBASSADOR')->name('api.v1.__auth.ambassador');
        });
    }

    public function test_unauthenticated_access_is_rejected(): void
    {
        $this->getJson('/api/v1/__auth/authenticated')
            ->assertStatus(401)
            ->assertJsonPath('error.code', ApiErrorCode::UNAUTHENTICATED);
    }

    public function test_authenticated_users_can_access_authenticated_routes(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/v1/__auth/authenticated')->assertOk();
    }

    public function test_admin_business_and_ambassador_access_is_role_scoped(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());
        $this->getJson('/api/v1/__auth/admin')->assertOk();
        $this->getJson('/api/v1/__auth/business')
            ->assertStatus(403)
            ->assertJsonPath('error.code', ApiErrorCode::FORBIDDEN);

        Sanctum::actingAs(User::factory()->business()->create());
        $this->getJson('/api/v1/__auth/business')->assertOk();
        $this->getJson('/api/v1/__auth/admin')->assertStatus(403);
        $this->getJson('/api/v1/__auth/ambassador')->assertStatus(403);

        Sanctum::actingAs(User::factory()->ambassador()->create());
        $this->getJson('/api/v1/__auth/ambassador')->assertOk();
        $this->getJson('/api/v1/__auth/admin')->assertStatus(403);
        $this->getJson('/api/v1/__auth/business')->assertStatus(403);
    }

    public function test_artisan_command_is_the_only_admin_provisioning_path(): void
    {
        $this->artisan('marcaturs:create-admin', [
            'email' => 'admin@example.com',
            '--name' => 'Platform Admin',
            '--password' => 'password123',
        ])->assertSuccessful();

        $this->assertDatabaseHas('users', [
            'email' => 'admin@example.com',
            'role' => 'ADMIN',
            'status' => 'active',
        ]);
    }
}
