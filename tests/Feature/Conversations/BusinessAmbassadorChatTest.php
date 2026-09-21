<?php

namespace Tests\Feature\Conversations;

use App\Enums\AccountStatus;
use App\Enums\CampaignStatus;
use App\Models\BusinessProfile;
use App\Models\Campaign;
use App\Models\CampaignVersion;
use App\Models\Conversation;
use App\Models\User;
use App\Support\Api\ApiErrorCode;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BusinessAmbassadorChatTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cannot_access_conversations(): void
    {
        $this->getJson('/api/v1/conversations')
            ->assertStatus(401)
            ->assertJsonPath('error.code', ApiErrorCode::UNAUTHENTICATED);
    }

    public function test_admin_cannot_use_participant_conversation_routes(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->getJson('/api/v1/conversations')
            ->assertStatus(403)
            ->assertJsonPath('error.code', ApiErrorCode::FORBIDDEN);
    }

    public function test_business_opens_new_conversation_with_ambassador(): void
    {
        $business = User::factory()->business()->create();
        BusinessProfile::factory()->for($business)->create();
        $ambassador = User::factory()->ambassador()->create();
        Sanctum::actingAs($business);

        $this->postJson('/api/v1/conversations', [
            'ambassador_id' => $ambassador->id,
        ])->assertCreated()
            ->assertJsonMissingPath('data.campaign')
            ->assertJsonPath('data.counterpart.id', $ambassador->id)
            ->assertJsonPath('data.counterpart.role', 'AMBASSADOR');

        $this->assertDatabaseHas('conversations', [
            'business_user_id' => $business->id,
            'ambassador_user_id' => $ambassador->id,
        ]);
        $this->assertDatabaseCount('conversation_participants', 2);
        $this->assertFalse(Schema::hasColumn('conversations', 'campaign_id'));
    }

    public function test_ambassador_opening_same_pair_returns_existing_conversation(): void
    {
        $business = User::factory()->business()->create();
        $ambassador = User::factory()->ambassador()->create();
        Sanctum::actingAs($business);
        $id = $this->postJson('/api/v1/conversations', [
            'ambassador_id' => $ambassador->id,
        ])->assertCreated()->json('data.id');

        $this->postJson('/api/v1/conversations', [
            'ambassador_id' => $ambassador->id,
        ])->assertOk()->assertJsonPath('data.id', $id);

        Sanctum::actingAs($ambassador);
        $this->postJson('/api/v1/conversations', [
            'business_id' => $business->id,
        ])->assertOk()->assertJsonPath('data.id', $id);

        $this->assertDatabaseCount('conversations', 1);
        $this->assertDatabaseCount('conversation_participants', 2);
    }

    public function test_campaign_cannot_be_used_by_business_to_open_a_conversation(): void
    {
        $business = User::factory()->business()->create();
        $ambassador = User::factory()->ambassador()->create();
        Sanctum::actingAs($business);

        $this->postJson('/api/v1/conversations', [
            'ambassador_id' => $ambassador->id,
            'campaign_id' => 1,
        ])->assertStatus(400)
            ->assertJsonPath('error.code', ApiErrorCode::VALIDATION_ERROR);
    }

    public function test_ambassador_can_open_conversation_from_discoverable_campaign_without_business_id(): void
    {
        $business = User::factory()->business()->create();
        BusinessProfile::factory()->for($business)->create();
        $campaign = Campaign::factory()->for($business)->create([
            'status' => CampaignStatus::Active,
            'listing_starts_at' => now(),
            'listing_expires_at' => now()->addDays(30),
        ]);
        $version = CampaignVersion::factory()->for($campaign)->published()->create();
        $campaign->forceFill(['current_campaign_version_id' => $version->id])->save();

        $ambassador = User::factory()->ambassador()->create();
        Sanctum::actingAs($ambassador);

        $this->postJson('/api/v1/conversations', [
            'campaign_id' => $campaign->id,
        ])->assertCreated()
            ->assertJsonMissingPath('data.campaign')
            ->assertJsonPath('data.counterpart.id', $business->id)
            ->assertJsonPath('data.counterpart.role', 'BUSINESS');

        $this->assertDatabaseHas('conversations', [
            'business_user_id' => $business->id,
            'ambassador_user_id' => $ambassador->id,
        ]);
        $this->assertFalse(Schema::hasColumn('conversations', 'campaign_id'));

        $this->postJson('/api/v1/conversations', [
            'campaign_id' => $campaign->id,
        ])->assertOk();

        $this->assertDatabaseCount('conversations', 1);
    }

    public function test_ambassador_cannot_supply_both_business_id_and_campaign_id(): void
    {
        $business = User::factory()->business()->create();
        $ambassador = User::factory()->ambassador()->create();
        Sanctum::actingAs($ambassador);

        $this->postJson('/api/v1/conversations', [
            'business_id' => $business->id,
            'campaign_id' => 1,
        ])->assertStatus(400)
            ->assertJsonPath('error.code', ApiErrorCode::VALIDATION_ERROR);
    }

    public function test_ambassador_cannot_open_conversation_from_non_discoverable_campaign(): void
    {
        $business = User::factory()->business()->create();
        BusinessProfile::factory()->for($business)->create();
        $campaign = Campaign::factory()->for($business)->create([
            'status' => CampaignStatus::Suspended,
        ]);
        $ambassador = User::factory()->ambassador()->create();
        Sanctum::actingAs($ambassador);

        $this->postJson('/api/v1/conversations', [
            'campaign_id' => $campaign->id,
        ])->assertStatus(404)
            ->assertJsonPath('error.code', ApiErrorCode::NOT_FOUND);
    }

    public function test_participants_can_exchange_messages_and_mark_read(): void
    {
        $business = User::factory()->business()->create();
        $ambassador = User::factory()->ambassador()->create();
        Sanctum::actingAs($ambassador);

        $conversationId = $this->postJson('/api/v1/conversations', [
            'business_id' => $business->id,
        ])->assertCreated()->json('data.id');

        $this->postJson('/api/v1/conversations/'.$conversationId.'/messages', [
            'content' => 'Hello.',
        ])->assertCreated()
            ->assertJsonPath('data.type', 'text')
            ->assertJsonPath('data.content', 'Hello.')
            ->assertJsonPath('data.sender_id', $ambassador->id);

        Sanctum::actingAs($business);
        $this->getJson('/api/v1/conversations')
            ->assertOk()
            ->assertJsonPath('data.0.id', $conversationId)
            ->assertJsonMissingPath('data.0.campaign');

        $this->postJson('/api/v1/conversations/'.$conversationId.'/messages', [
            'content' => 'Welcome.',
        ])->assertCreated();

        $this->getJson('/api/v1/conversations/'.$conversationId.'/messages')
            ->assertOk()
            ->assertJsonPath('data.0.content', 'Hello.')
            ->assertJsonPath('data.1.content', 'Welcome.')
            ->assertJsonPath('meta.pagination.total', 2);

        Sanctum::actingAs($ambassador);
        $this->postJson('/api/v1/conversations/'.$conversationId.'/read')
            ->assertOk()
            ->assertJsonPath('data.updated', 1);

        $this->assertNotNull(
            Conversation::query()->findOrFail($conversationId)->messages()->where('sender_id', $business->id)->value('read_at'),
        );
    }

    public function test_business_and_ambassador_cannot_open_against_the_wrong_role(): void
    {
        $business = User::factory()->business()->create();
        $otherBusiness = User::factory()->business()->create();
        $ambassador = User::factory()->ambassador()->create();
        $otherAmbassador = User::factory()->ambassador()->create();

        Sanctum::actingAs($business);
        $this->postJson('/api/v1/conversations', [
            'ambassador_id' => $otherBusiness->id,
        ])->assertStatus(400);

        Sanctum::actingAs($ambassador);
        $this->postJson('/api/v1/conversations', [
            'business_id' => $otherAmbassador->id,
        ])->assertStatus(400);
    }

    public function test_idor_and_role_isolation(): void
    {
        $business = User::factory()->business()->create();
        $ambassador = User::factory()->ambassador()->create();
        Sanctum::actingAs($ambassador);
        $conversationId = $this->postJson('/api/v1/conversations', [
            'business_id' => $business->id,
        ])->assertCreated()->json('data.id');

        Sanctum::actingAs(User::factory()->ambassador()->create());
        $this->getJson('/api/v1/conversations/'.$conversationId)->assertStatus(404);
        $this->getJson('/api/v1/conversations/'.$conversationId.'/messages')->assertStatus(404);
        $this->postJson('/api/v1/conversations/'.$conversationId.'/messages', [
            'content' => 'Intrusion',
        ])->assertStatus(404);

        $otherBusiness = User::factory()->business()->create();
        BusinessProfile::factory()->for($otherBusiness)->create();
        Sanctum::actingAs($otherBusiness);
        $this->getJson('/api/v1/conversations/'.$conversationId)->assertStatus(404);
    }

    public function test_empty_and_invalid_messages_are_rejected(): void
    {
        $business = User::factory()->business()->create();
        $ambassador = User::factory()->ambassador()->create();
        Sanctum::actingAs($ambassador);
        $conversationId = $this->postJson('/api/v1/conversations', [
            'business_id' => $business->id,
        ])->json('data.id');

        $this->postJson('/api/v1/conversations/'.$conversationId.'/messages', [
            'content' => '   ',
        ])->assertStatus(400)
            ->assertJsonPath('error.code', ApiErrorCode::VALIDATION_ERROR);

        $this->postJson('/api/v1/conversations/'.$conversationId.'/messages', [])
            ->assertStatus(400);
    }

    public function test_restricted_and_suspended_accounts_cannot_use_chat(): void
    {
        $business = User::factory()->business()->create();
        $ambassador = User::factory()->ambassador()->create();
        Sanctum::actingAs($ambassador);
        $conversationId = $this->postJson('/api/v1/conversations', [
            'business_id' => $business->id,
        ])->json('data.id');

        $ambassador->forceFill(['status' => AccountStatus::Restricted])->save();
        $this->postJson('/api/v1/conversations/'.$conversationId.'/messages', [
            'content' => 'Still here',
        ])->assertStatus(403);

        $ambassador->forceFill(['status' => AccountStatus::Suspended])->save();
        $this->getJson('/api/v1/conversations')->assertStatus(403);

        $ambassador->forceFill(['status' => AccountStatus::Banned])->save();
        $this->getJson('/api/v1/conversations')->assertStatus(403);
    }

    public function test_participant_can_report_and_admin_can_review_reported_only(): void
    {
        $business = User::factory()->business()->create();
        $ambassador = User::factory()->ambassador()->create();
        Sanctum::actingAs($ambassador);
        $conversationId = $this->postJson('/api/v1/conversations', [
            'business_id' => $business->id,
        ])->json('data.id');
        $this->postJson('/api/v1/conversations/'.$conversationId.'/messages', [
            'content' => 'Private message',
        ])->assertCreated();

        $unreported = Conversation::factory()->create();

        $this->postJson('/api/v1/conversations/'.$conversationId.'/report', [
            'reason' => 'Harassment in this thread.',
        ])->assertOk()
            ->assertJsonPath('data.reported', true)
            ->assertJsonMissingPath('data.report_reason');

        $this->postJson('/api/v1/conversations/'.$conversationId.'/report', [
            'reason' => 'Again',
        ])->assertStatus(409)
            ->assertJsonPath('error.code', ApiErrorCode::CONFLICT);

        Sanctum::actingAs(User::factory()->admin()->create());
        $this->getJson('/api/v1/admin/conversations')
            ->assertOk()
            ->assertJsonPath('data.0.id', $conversationId)
            ->assertJsonPath('data.0.report_reason', 'Harassment in this thread.')
            ->assertJsonPath('data.0.business.id', $business->id)
            ->assertJsonMissingPath('data.0.campaign')
            ->assertJsonPath('meta.pagination.total', 1);

        $this->getJson('/api/v1/admin/conversations/'.$conversationId.'/messages')
            ->assertOk()
            ->assertJsonPath('data.0.content', 'Private message');

        $this->getJson('/api/v1/admin/conversations/'.$unreported->id)
            ->assertStatus(404);
    }

    public function test_conversations_table_enforces_business_ambassador_uniqueness(): void
    {
        $indexes = collect(Schema::getIndexes('conversations'));

        $this->assertTrue(
            $indexes->contains(function (array $index): bool {
                return ($index['name'] ?? '') === 'conversations_business_ambassador_uq'
                    && ($index['unique'] ?? false) === true;
            }),
        );

        $conversation = Conversation::factory()->create();

        $this->expectException(UniqueConstraintViolationException::class);
        Conversation::factory()->create([
            'business_user_id' => $conversation->business_user_id,
            'ambassador_user_id' => $conversation->ambassador_user_id,
        ]);
    }
}
