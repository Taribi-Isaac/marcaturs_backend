<?php

namespace Tests\Feature\Profiles;

use App\Models\AmbassadorProfile;
use App\Models\User;
use App\Support\Api\ApiErrorCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AmbassadorProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_ambassador_can_create_and_retrieve_their_profile(): void
    {
        $user = User::factory()->ambassador()->create();
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/ambassadors/me', $this->payload())
            ->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.display_name', 'Chidi Promoter')
            ->assertJsonMissingPath('data.password')
            ->assertJsonMissingPath('data.certification_status')
            ->assertJsonMissingPath('data.verification_status');

        $this->getJson('/api/v1/ambassadors/me')
            ->assertOk()
            ->assertJsonPath('data.location', 'Abuja')
            ->assertJsonPath('data.skills.0', 'social-media');

        $this->assertDatabaseHas('ambassador_profiles', [
            'user_id' => $user->id,
            'display_name' => 'Chidi Promoter',
        ]);
        $this->assertTrue($user->fresh()->ambassadorProfile()->exists());
        $this->assertFalse($user->fresh()->businessProfile()->exists());
    }

    public function test_an_ambassador_can_update_their_profile(): void
    {
        $user = User::factory()->ambassador()->create();
        AmbassadorProfile::factory()->for($user)->create(['display_name' => 'Old']);
        Sanctum::actingAs($user);

        $this->patchJson('/api/v1/ambassadors/me', [
            'display_name' => 'New Name',
            'role' => 'ADMIN',
            'status' => 'banned',
            'user_id' => 999,
            'verification_status' => 'approved',
        ])
            ->assertOk()
            ->assertJsonPath('data.display_name', 'New Name')
            ->assertJsonMissingPath('data.verification_status');

        $this->assertSame('AMBASSADOR', $user->fresh()->role->value);
        $this->assertSame('active', $user->fresh()->status->value);
        $this->assertSame($user->id, $user->ambassadorProfile()->first()?->user_id);
    }

    public function test_duplicate_ambassador_profile_creation_is_rejected(): void
    {
        $user = User::factory()->ambassador()->create();
        AmbassadorProfile::factory()->for($user)->create();
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/ambassadors/me', $this->payload())
            ->assertStatus(409)
            ->assertJsonPath('error.code', ApiErrorCode::CONFLICT);
    }

    public function test_a_business_cannot_use_ambassador_profile_endpoints(): void
    {
        Sanctum::actingAs(User::factory()->business()->create());

        $this->getJson('/api/v1/ambassadors/me')
            ->assertStatus(403)
            ->assertJsonPath('error.code', ApiErrorCode::FORBIDDEN);
    }

    public function test_another_ambassador_cannot_be_addressed_by_id(): void
    {
        $owner = User::factory()->ambassador()->create();
        $profile = AmbassadorProfile::factory()->for($owner)->create();
        Sanctum::actingAs(User::factory()->ambassador()->create());

        $this->patchJson('/api/v1/ambassadors/'.$profile->id, ['display_name' => 'Hijack'])
            ->assertStatus(404);
    }

    public function test_unauthenticated_access_is_rejected(): void
    {
        $this->postJson('/api/v1/ambassadors/me', $this->payload())
            ->assertStatus(401)
            ->assertJsonPath('error.code', ApiErrorCode::UNAUTHENTICATED);
    }

    public function test_suspended_users_cannot_manage_ambassador_profiles(): void
    {
        $user = User::factory()->ambassador()->suspended()->create();
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/ambassadors/me', $this->payload())->assertStatus(403);
    }

    public function test_deleting_a_user_removes_the_profile(): void
    {
        $user = User::factory()->ambassador()->create();
        AmbassadorProfile::factory()->for($user)->create();

        $user->delete();

        $this->assertDatabaseCount('ambassador_profiles', 0);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(): array
    {
        return [
            'display_name' => 'Chidi Promoter',
            'profile_description' => 'Campus and market promoter',
            'location' => 'Abuja',
            'skills' => ['social-media', 'field-sales'],
            'marketing_interests' => ['education', 'retail'],
            'experience' => '3 years promoting consumer brands',
        ];
    }
}
