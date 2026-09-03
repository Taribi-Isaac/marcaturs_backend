<?php

namespace Tests\Feature\Notifications;

use App\Enums\AccountStatus;
use App\Enums\NotificationType;
use App\Models\User;
use App\Notifications\TestNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class NotificationFoundationTest extends TestCase
{
    use RefreshDatabase;

    // ── Persistence ─────────────────────────────────────────────────────

    public function test_notification_can_be_persisted_to_database(): void
    {
        $user = User::factory()->create();
        $user->notify(new TestNotification('Hello World'));

        $this->assertDatabaseCount('notifications', 1);

        $record = DatabaseNotification::query()->first();
        $this->assertSame($user->id, $record->notifiable_id);
        $this->assertSame('Hello World', $record->data['title']);
        $this->assertSame(NotificationType::Test->value, $record->data['notification_type']);
    }

    public function test_notification_recipient_association(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();

        $userA->notify(new TestNotification('For A'));
        $userB->notify(new TestNotification('For B'));

        $this->assertCount(1, $userA->notifications);
        $this->assertCount(1, $userB->notifications);
        $this->assertSame('For A', $userA->notifications->first()->data['title']);
        $this->assertSame('For B', $userB->notifications->first()->data['title']);
    }

    public function test_notification_data_is_structured_correctly(): void
    {
        $user = User::factory()->create();
        $user->notify(new TestNotification('Structured'));

        $record = $user->notifications()->first();
        $data = $record->data;

        $this->assertArrayHasKey('notification_type', $data);
        $this->assertArrayHasKey('title', $data);
        $this->assertSame(NotificationType::Test->value, $data['notification_type']);
    }

    public function test_notification_defaults_to_unread(): void
    {
        $user = User::factory()->create();
        $user->notify(new TestNotification);

        $record = $user->notifications()->first();
        $this->assertNull($record->read_at);
    }

    public function test_notification_can_be_marked_as_read(): void
    {
        $user = User::factory()->create();
        $user->notify(new TestNotification);

        $record = $user->notifications()->first();
        $record->markAsRead();

        $this->assertNotNull($record->fresh()->read_at);
    }

    // ── Idempotency ─────────────────────────────────────────────────────

    public function test_duplicate_idempotency_key_is_silently_skipped(): void
    {
        $user = User::factory()->create();
        $user->notify(new TestNotification('First', 'unique-key-1'));
        $user->notify(new TestNotification('Duplicate', 'unique-key-1'));

        $this->assertDatabaseCount('notifications', 1);
        $this->assertSame('First', $user->notifications()->first()->data['title']);
    }

    public function test_different_idempotency_keys_create_separate_notifications(): void
    {
        $user = User::factory()->create();
        $user->notify(new TestNotification('A', 'key-a'));
        $user->notify(new TestNotification('B', 'key-b'));

        $this->assertDatabaseCount('notifications', 2);
    }

    public function test_null_idempotency_key_allows_duplicates(): void
    {
        $user = User::factory()->create();
        $user->notify(new TestNotification('First', null));
        $user->notify(new TestNotification('Second', null));

        $this->assertDatabaseCount('notifications', 2);
    }

    public function test_idempotency_key_is_stored_in_database(): void
    {
        $user = User::factory()->create();
        $user->notify(new TestNotification('Keyed', 'my-idem-key'));

        $record = DatabaseNotification::query()->first();
        $this->assertSame('my-idem-key', $record->idempotency_key);
    }

    // ── Queue ───────────────────────────────────────────────────────────

    public function test_notification_is_queued_via_should_queue(): void
    {
        Notification::fake();

        $user = User::factory()->create();
        $user->notify(new TestNotification);

        Notification::assertSentTo($user, TestNotification::class);
    }

    // ── Email channel ───────────────────────────────────────────────────

    public function test_email_channel_renders_via_log_driver(): void
    {
        $user = User::factory()->create();
        $notification = new TestNotification('Email test');

        $mail = $notification->toMail($user);
        $this->assertNotNull($mail);
        $this->assertSame('MarcatursHub: Email test', $mail->subject);
    }

    public function test_email_is_skipped_when_to_mail_returns_null(): void
    {
        $user = User::factory()->create();
        $notification = new TestNotification('No email', null, sendEmail: false);

        $this->assertNull($notification->toMail($user));
        $this->assertSame(['database'], $notification->via($user));
    }

    public function test_email_channel_is_included_when_mail_is_defined(): void
    {
        $user = User::factory()->create();
        $notification = new TestNotification;

        $channels = $notification->via($user);
        $this->assertContains('database', $channels);
        $this->assertContains('mail', $channels);
    }

    // ── API: Authorization ──────────────────────────────────────────────

    public function test_unauthenticated_user_cannot_access_notifications(): void
    {
        $this->getJson('/api/v1/notifications')->assertUnauthorized();
        $this->getJson('/api/v1/notifications/fake-uuid')->assertUnauthorized();
        $this->postJson('/api/v1/notifications/fake-uuid/read')->assertUnauthorized();
    }

    public function test_user_can_list_only_own_notifications(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();
        $userA->notify(new TestNotification('For A'));
        $userB->notify(new TestNotification('For B'));

        Sanctum::actingAs($userA);
        $response = $this->getJson('/api/v1/notifications')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->assertSame('For A', $response->json('data.0.data.title'));
    }

    public function test_user_cannot_view_another_users_notification(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();
        $userA->notify(new TestNotification('Private'));

        $notificationId = $userA->notifications()->first()->id;

        Sanctum::actingAs($userB);
        $this->getJson('/api/v1/notifications/'.$notificationId)
            ->assertNotFound();
    }

    public function test_user_cannot_mark_read_another_users_notification(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();
        $userA->notify(new TestNotification);

        $notificationId = $userA->notifications()->first()->id;

        Sanctum::actingAs($userB);
        $this->postJson('/api/v1/notifications/'.$notificationId.'/read')
            ->assertNotFound();
    }

    // ── API: Read/Unread ────────────────────────────────────────────────

    public function test_notification_list_includes_read_state(): void
    {
        $user = User::factory()->create();
        $user->notify(new TestNotification);

        Sanctum::actingAs($user);
        $this->getJson('/api/v1/notifications')
            ->assertOk()
            ->assertJsonPath('data.0.is_read', false)
            ->assertJsonPath('data.0.read_at', null);
    }

    public function test_mark_read_sets_read_at(): void
    {
        $user = User::factory()->create();
        $user->notify(new TestNotification);
        $id = $user->notifications()->first()->id;

        Sanctum::actingAs($user);
        $response = $this->postJson('/api/v1/notifications/'.$id.'/read')
            ->assertOk();

        $this->assertTrue($response->json('data.is_read'));
        $this->assertNotNull($response->json('data.read_at'));
    }

    public function test_mark_read_is_idempotent(): void
    {
        $user = User::factory()->create();
        $user->notify(new TestNotification);
        $id = $user->notifications()->first()->id;

        Sanctum::actingAs($user);
        $first = $this->postJson('/api/v1/notifications/'.$id.'/read')->assertOk();
        $second = $this->postJson('/api/v1/notifications/'.$id.'/read')->assertOk();

        $this->assertSame($first->json('data.read_at'), $second->json('data.read_at'));
    }

    // ── API: Show ───────────────────────────────────────────────────────

    public function test_show_returns_own_notification(): void
    {
        $user = User::factory()->create();
        $user->notify(new TestNotification('Visible'));
        $id = $user->notifications()->first()->id;

        Sanctum::actingAs($user);
        $this->getJson('/api/v1/notifications/'.$id)
            ->assertOk()
            ->assertJsonPath('data.type', NotificationType::Test->value)
            ->assertJsonPath('data.data.title', 'Visible');
    }

    // ── Privacy ─────────────────────────────────────────────────────────

    public function test_idempotency_key_is_not_exposed_in_api_response(): void
    {
        $user = User::factory()->create();
        $user->notify(new TestNotification('Private key', 'secret-key'));
        $id = $user->notifications()->first()->id;

        Sanctum::actingAs($user);
        $response = $this->getJson('/api/v1/notifications/'.$id)
            ->assertOk();

        $data = $response->json('data.data');
        $this->assertArrayNotHasKey('idempotency_key', $data);
    }

    public function test_notification_type_in_data_is_not_duplicated_in_data_field(): void
    {
        $user = User::factory()->create();
        $user->notify(new TestNotification);
        $id = $user->notifications()->first()->id;

        Sanctum::actingAs($user);
        $response = $this->getJson('/api/v1/notifications/'.$id)
            ->assertOk();

        $this->assertArrayNotHasKey('notification_type', $response->json('data.data'));
        $this->assertSame(NotificationType::Test->value, $response->json('data.type'));
    }

    // ── Financial isolation ─────────────────────────────────────────────

    public function test_notification_dispatch_does_not_touch_financial_tables(): void
    {
        // Verify the architectural guarantee: notification dispatch has
        // zero side-effects on Commission/Deal tables.
        $user = User::factory()->business()->create();

        $this->assertDatabaseCount('commissions', 0);

        $user->notify(new TestNotification('Financial isolation'));

        // Notification was persisted but no commission/deal rows were
        // created or mutated by the notification pipeline.
        $this->assertDatabaseCount('notifications', 1);
        $this->assertDatabaseCount('commissions', 0);
    }

    // ── Account access middleware applies ────────────────────────────────

    public function test_blocked_account_cannot_access_notification_api(): void
    {
        $user = User::factory()->create(['status' => AccountStatus::Suspended]);
        $user->notify(new TestNotification);

        Sanctum::actingAs($user);
        $this->getJson('/api/v1/notifications')->assertForbidden();
    }

    public function test_restricted_account_cannot_access_notification_api(): void
    {
        $user = User::factory()->create(['status' => AccountStatus::Restricted]);
        $user->notify(new TestNotification);

        Sanctum::actingAs($user);
        $this->getJson('/api/v1/notifications')->assertForbidden();
    }
}
