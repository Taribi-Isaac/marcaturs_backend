<?php

namespace Tests\Feature\Profiles;

use App\Models\BusinessProfile;
use App\Models\User;
use App\Support\Api\ApiErrorCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BusinessProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_business_can_create_and_retrieve_their_profile(): void
    {
        $user = User::factory()->business()->create();
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/businesses/me', $this->payload())
            ->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.legal_name', 'Ada Ventures Ltd')
            ->assertJsonMissingPath('data.password')
            ->assertJsonMissingPath('data.role')
            ->assertJsonMissingPath('data.status');

        $this->getJson('/api/v1/businesses/me')
            ->assertOk()
            ->assertJsonPath('data.legal_name', 'Ada Ventures Ltd')
            ->assertJsonPath('data.trading_name', 'Ada Store');

        $this->assertDatabaseHas('business_profiles', [
            'user_id' => $user->id,
            'legal_name' => 'Ada Ventures Ltd',
        ]);
        $this->assertTrue($user->fresh()->businessProfile()->exists());
        $this->assertFalse($user->fresh()->ambassadorProfile()->exists());
    }

    public function test_a_business_can_update_their_profile(): void
    {
        $user = User::factory()->business()->create();
        BusinessProfile::factory()->for($user)->create(['legal_name' => 'Old Name']);
        Sanctum::actingAs($user);

        $this->patchJson('/api/v1/businesses/me', [
            'legal_name' => 'New Name',
            'description' => 'Updated description',
            'role' => 'ADMIN',
            'status' => 'banned',
            'user_id' => 999,
        ])
            ->assertOk()
            ->assertJsonPath('data.legal_name', 'New Name')
            ->assertJsonPath('data.description', 'Updated description');

        $this->assertSame('BUSINESS', $user->fresh()->role->value);
        $this->assertSame('active', $user->fresh()->status->value);
        $this->assertSame($user->id, $user->businessProfile()->first()?->user_id);
        $this->assertDatabaseMissing('business_profiles', ['user_id' => 999]);
    }

    public function test_duplicate_business_profile_creation_is_rejected(): void
    {
        $user = User::factory()->business()->create();
        BusinessProfile::factory()->for($user)->create();
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/businesses/me', $this->payload())
            ->assertStatus(409)
            ->assertJsonPath('error.code', ApiErrorCode::CONFLICT);
    }

    public function test_missing_business_profile_returns_not_found(): void
    {
        Sanctum::actingAs(User::factory()->business()->create());

        $this->getJson('/api/v1/businesses/me')
            ->assertStatus(404)
            ->assertJsonPath('error.code', ApiErrorCode::NOT_FOUND);

        $this->patchJson('/api/v1/businesses/me', ['legal_name' => 'Later'])
            ->assertStatus(404)
            ->assertJsonPath('error.code', ApiErrorCode::NOT_FOUND);
    }

    public function test_registration_does_not_auto_create_a_business_profile(): void
    {
        $this->postJson('/api/v1/auth/register', [
            'name' => 'Biz',
            'email' => 'profile-reg@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'role' => 'BUSINESS',
        ])->assertCreated();

        $this->assertDatabaseCount('business_profiles', 0);
    }

    public function test_an_ambassador_cannot_use_business_profile_endpoints(): void
    {
        Sanctum::actingAs(User::factory()->ambassador()->create());

        $this->getJson('/api/v1/businesses/me')
            ->assertStatus(403)
            ->assertJsonPath('error.code', ApiErrorCode::FORBIDDEN);

        $this->postJson('/api/v1/businesses/me', $this->payload())
            ->assertStatus(403);
    }

    public function test_an_admin_cannot_use_business_participant_endpoints(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->getJson('/api/v1/businesses/me')->assertStatus(403);
        $this->postJson('/api/v1/businesses/me', $this->payload())->assertStatus(403);
    }

    public function test_another_business_cannot_be_addressed_by_id(): void
    {
        $owner = User::factory()->business()->create();
        $profile = BusinessProfile::factory()->for($owner)->create();
        Sanctum::actingAs(User::factory()->business()->create());

        $this->getJson('/api/v1/businesses/'.$profile->id)
            ->assertStatus(404)
            ->assertJsonPath('error.code', ApiErrorCode::NOT_FOUND);

        $this->patchJson('/api/v1/businesses/'.$profile->id, ['legal_name' => 'Hijack'])
            ->assertStatus(404);
    }

    public function test_unauthenticated_access_is_rejected(): void
    {
        $this->getJson('/api/v1/businesses/me')
            ->assertStatus(401)
            ->assertJsonPath('error.code', ApiErrorCode::UNAUTHENTICATED);
    }

    public function test_suspended_and_banned_users_cannot_manage_profiles(): void
    {
        $suspended = User::factory()->business()->suspended()->create();
        BusinessProfile::factory()->for($suspended)->create();
        Sanctum::actingAs($suspended);
        $this->getJson('/api/v1/businesses/me')->assertStatus(403);

        $banned = User::factory()->business()->banned()->create();
        Sanctum::actingAs($banned);
        $this->postJson('/api/v1/businesses/me', $this->payload())->assertStatus(403);
    }

    public function test_restricted_users_cannot_manage_profiles(): void
    {
        $user = User::factory()->business()->restricted()->create();
        BusinessProfile::factory()->for($user)->create();
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/businesses/me')
            ->assertStatus(403)
            ->assertJsonPath('error.code', ApiErrorCode::FORBIDDEN);
    }

    public function test_create_requires_legal_name(): void
    {
        Sanctum::actingAs(User::factory()->business()->create());

        $this->postJson('/api/v1/businesses/me', [])
            ->assertStatus(400)
            ->assertJsonPath('error.code', ApiErrorCode::VALIDATION_ERROR);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(): array
    {
        return [
            'legal_name' => 'Ada Ventures Ltd',
            'trading_name' => 'Ada Store',
            'description' => 'Retail business',
            'category' => 'retail',
            'address' => '12 Marina, Lagos',
            'operating_location' => 'Lagos',
            'contact_email' => 'ops@example.com',
            'contact_phone' => '08012345678',
            'website' => 'https://example.com',
            'social_links' => [
                'instagram' => 'https://instagram.com/ada',
            ],
        ];
    }
}
