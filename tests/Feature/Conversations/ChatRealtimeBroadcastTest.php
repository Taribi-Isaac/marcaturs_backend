<?php

namespace Tests\Feature\Conversations;

use App\Enums\AccountStatus;
use App\Events\Conversations\MessageCreated;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Services\Conversations\MessageCreatedBroadcaster;
use App\Support\Api\ApiErrorCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Tests\TestCase;

class ChatRealtimeBroadcastTest extends TestCase
{
    use RefreshDatabase;

    public function test_sending_a_message_persists_then_dispatches_message_created(): void
    {
        Event::fake([MessageCreated::class]);

        [$business, $ambassador, $conversationId] = $this->openConversation();
        Sanctum::actingAs($ambassador);

        $this->postJson('/api/v1/conversations/'.$conversationId.'/messages', [
            'content' => 'Hello over the wire.',
        ])->assertCreated()
            ->assertJsonPath('data.content', 'Hello over the wire.');

        $this->assertDatabaseCount('messages', 1);
        $message = Message::query()->firstOrFail();

        Event::assertDispatched(MessageCreated::class, function (MessageCreated $event) use ($conversationId, $message): bool {
            $this->assertTrue(Message::query()->whereKey($message->id)->exists());

            $payload = $event->broadcastWith();
            $channels = collect($event->broadcastOn())->map(fn ($channel) => $channel->name);

            $this->assertSame(['id', 'conversation_id', 'sender_id', 'type', 'content', 'read_at', 'created_at'], array_keys($payload));
            $this->assertSame($message->id, $payload['id']);
            $this->assertSame($conversationId, $payload['conversation_id']);
            $this->assertSame($message->sender_id, $payload['sender_id']);
            $this->assertSame('text', $payload['type']);
            $this->assertSame('Hello over the wire.', $payload['content']);
            $this->assertNull($payload['read_at']);
            $this->assertNotNull($payload['created_at']);
            $this->assertTrue($channels->contains('private-conversation.'.$conversationId));
            $this->assertSame('message.created', $event->broadcastAs());

            return true;
        });
    }

    public function test_broadcast_failure_does_not_roll_back_or_duplicate_the_message(): void
    {
        $this->mock(MessageCreatedBroadcaster::class, function ($mock): void {
            $mock->shouldReceive('publish')->once()->andThrow(new RuntimeException('Reverb unavailable'));
        });

        [, $ambassador, $conversationId] = $this->openConversation();
        Sanctum::actingAs($ambassador);

        $this->postJson('/api/v1/conversations/'.$conversationId.'/messages', [
            'content' => 'Still stored.',
        ])->assertCreated()
            ->assertJsonPath('data.content', 'Still stored.');

        $this->assertDatabaseCount('messages', 1);
        $this->assertDatabaseHas('messages', [
            'conversation_id' => $conversationId,
            'content' => 'Still stored.',
        ]);
    }

    public function test_participant_can_authorize_the_private_conversation_channel(): void
    {
        $this->useReverbChannelAuthDriver();

        [$business, $ambassador, $conversationId] = $this->openConversation();
        Sanctum::actingAs($ambassador);

        $this->authorizeChannel($conversationId)
            ->assertOk()
            ->assertJsonStructure(['auth']);

        Sanctum::actingAs($business);
        $this->authorizeChannel($conversationId)
            ->assertOk()
            ->assertJsonStructure(['auth']);
    }

    public function test_non_participant_cannot_authorize_another_conversation(): void
    {
        $this->useReverbChannelAuthDriver();

        [, , $conversationId] = $this->openConversation();
        $outsider = User::factory()->ambassador()->create();
        Sanctum::actingAs($outsider);

        $this->authorizeChannel($conversationId)->assertStatus(403);
    }

    public function test_participant_of_one_conversation_cannot_subscribe_to_another(): void
    {
        $this->useReverbChannelAuthDriver();

        [, $ambassadorA, $conversationA] = $this->openConversation();
        [, , $conversationB] = $this->openConversation();

        Sanctum::actingAs($ambassadorA);
        $this->authorizeChannel($conversationA)->assertOk();
        $this->authorizeChannel($conversationB)->assertStatus(403);
    }

    public function test_guessed_conversation_ids_cannot_be_subscribed(): void
    {
        $this->useReverbChannelAuthDriver();

        [, $ambassador] = $this->openConversation();
        Sanctum::actingAs($ambassador);

        $this->authorizeChannel(999_999)->assertStatus(403);
    }

    public function test_business_and_ambassador_isolation_and_admin_denied(): void
    {
        $this->useReverbChannelAuthDriver();

        [$business, $ambassador, $conversationId] = $this->openConversation();
        $otherBusiness = User::factory()->business()->create();
        $otherAmbassador = User::factory()->ambassador()->create();

        Sanctum::actingAs($otherBusiness);
        $this->authorizeChannel($conversationId)->assertStatus(403);

        Sanctum::actingAs($otherAmbassador);
        $this->authorizeChannel($conversationId)->assertStatus(403);

        Sanctum::actingAs(User::factory()->admin()->create());
        $this->authorizeChannel($conversationId)->assertStatus(403);

        Sanctum::actingAs($business);
        $this->authorizeChannel($conversationId)->assertOk();
        Sanctum::actingAs($ambassador);
        $this->authorizeChannel($conversationId)->assertOk();
    }

    public function test_restricted_suspended_and_banned_accounts_cannot_authorize_channels(): void
    {
        $this->useReverbChannelAuthDriver();

        [, $ambassador, $conversationId] = $this->openConversation();
        Sanctum::actingAs($ambassador);

        $ambassador->forceFill(['status' => AccountStatus::Restricted])->save();
        $this->authorizeChannel($conversationId)
            ->assertStatus(403)
            ->assertJsonPath('error.code', ApiErrorCode::FORBIDDEN);

        $ambassador->forceFill(['status' => AccountStatus::Suspended])->save();
        $this->authorizeChannel($conversationId)
            ->assertStatus(403)
            ->assertJsonPath('error.code', ApiErrorCode::FORBIDDEN);

        $ambassador->forceFill(['status' => AccountStatus::Banned])->save();
        $this->authorizeChannel($conversationId)
            ->assertStatus(403)
            ->assertJsonPath('error.code', ApiErrorCode::FORBIDDEN);
    }

    public function test_guest_cannot_authorize_a_conversation_channel(): void
    {
        $this->useReverbChannelAuthDriver();

        [, , $conversationId] = $this->openConversation();

        auth()->forgetGuards();

        $this->authorizeChannel($conversationId)
            ->assertStatus(401)
            ->assertJsonPath('error.code', ApiErrorCode::UNAUTHENTICATED);
    }

    /**
     * @return array{0: User, 1: User, 2: int}
     */
    private function openConversation(): array
    {
        $business = User::factory()->business()->create();
        $ambassador = User::factory()->ambassador()->create();
        Sanctum::actingAs($ambassador);

        $conversationId = $this->postJson('/api/v1/conversations', [
            'business_id' => $business->id,
        ])->assertCreated()->json('data.id');

        $this->assertNotNull(Conversation::query()->find($conversationId));

        return [$business, $ambassador, $conversationId];
    }

    private function useReverbChannelAuthDriver(): void
    {
        config([
            'broadcasting.default' => 'reverb',
            'broadcasting.connections.reverb.key' => 'test-reverb-key',
            'broadcasting.connections.reverb.secret' => 'test-reverb-secret',
            'broadcasting.connections.reverb.app_id' => 'test-reverb-app',
            'broadcasting.connections.reverb.options.host' => '127.0.0.1',
            'broadcasting.connections.reverb.options.port' => 8080,
            'broadcasting.connections.reverb.options.scheme' => 'http',
            'broadcasting.connections.reverb.options.useTLS' => false,
        ]);
        Broadcast::purge();
        require base_path('routes/channels.php');
    }

    private function authorizeChannel(int $conversationId)
    {
        return $this->postJson('/api/broadcasting/auth', [
            'socket_id' => '1234.5678',
            'channel_name' => 'private-conversation.'.$conversationId,
        ]);
    }
}
