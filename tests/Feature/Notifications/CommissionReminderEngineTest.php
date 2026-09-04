<?php

namespace Tests\Feature\Notifications;

use App\Enums\AccountStatus;
use App\Enums\CampaignStatus;
use App\Enums\NotificationType;
use App\Enums\PaymentEvidenceKind;
use App\Models\Campaign;
use App\Models\CampaignVersion;
use App\Models\Commission;
use App\Models\Deal;
use App\Models\User;
use App\Notifications\CommissionNotification;
use App\Services\Notifications\CommissionReminderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CommissionReminderEngineTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake((string) config('deals.payment_evidence_disk'));
    }

    // ── Reminder timing slots ───────────────────────────────────────────

    public function test_pre_deadline_slot_dispatches_to_business_only(): void
    {
        $commission = $this->createSealedCommission();
        $dueAt = now()->startOfSecond()->addDays(2);
        $commission->forceFill(['due_at' => $dueAt])->save();

        Notification::fake();

        app(CommissionReminderService::class)->processReminders(now());

        Notification::assertSentTo(
            $commission->business,
            CommissionNotification::class,
            fn (CommissionNotification $n) => $n->notificationType() === NotificationType::CommissionPreDeadline,
        );

        Notification::assertNotSentTo(
            $commission->ambassador,
            CommissionNotification::class,
            fn (CommissionNotification $n) => $n->notificationType()->isBusinessPaymentPressureReminder(),
        );
    }

    public function test_deadline_slot_dispatches_on_due_at(): void
    {
        $commission = $this->createSealedCommission();
        $dueAt = now()->startOfSecond();
        $commission->forceFill(['due_at' => $dueAt])->save();

        Notification::fake();
        app(CommissionReminderService::class)->processReminders(now());

        Notification::assertSentTo(
            $commission->business,
            CommissionNotification::class,
            fn (CommissionNotification $n) => $n->notificationType() === NotificationType::CommissionDeadline,
        );
    }

    public function test_overdue_day_one_slot_at_due_at_plus_one(): void
    {
        $commission = $this->createSealedCommission();
        $dueAt = now()->startOfSecond()->subDay();
        $commission->forceFill(['due_at' => $dueAt])->save();

        Notification::fake();
        app(CommissionReminderService::class)->processReminders(now());

        Notification::assertSentTo(
            $commission->business,
            CommissionNotification::class,
            fn (CommissionNotification $n) => $n->notificationType() === NotificationType::CommissionOverdue,
        );
    }

    public function test_overdue_follow_up_slots_at_plus_four_and_plus_seven(): void
    {
        $commission = $this->createSealedCommission();
        $dueAt = now()->startOfSecond()->subDays(4);
        $commission->forceFill(['due_at' => $dueAt])->save();

        Notification::fake();
        app(CommissionReminderService::class)->processReminders(now());

        Notification::assertSentTo(
            $commission->business,
            CommissionNotification::class,
            fn (CommissionNotification $n) => $n->notificationType() === NotificationType::CommissionOverdueFollowUp
                && $n->idempotencyKey() === sprintf(
                    'commission:%d:reminder:%s:%s:%d',
                    $commission->id,
                    NotificationType::CommissionOverdueFollowUp->value,
                    $dueAt->copy()->addDays(4)->toDateString(),
                    $commission->business_user_id,
                ),
        );
    }

    public function test_fifth_slot_at_due_at_plus_seven_and_no_sixth(): void
    {
        $commission = $this->createSealedCommission();
        $dueAt = now()->startOfSecond()->subDays(7);
        $commission->forceFill(['due_at' => $dueAt])->save();

        // Persist real notifications to count Business pressure reminders.
        app(CommissionReminderService::class)->processReminders(now());
        app(CommissionReminderService::class)->processReminders(now());

        $pressureTypes = [
            NotificationType::CommissionPreDeadline->value,
            NotificationType::CommissionDeadline->value,
            NotificationType::CommissionOverdue->value,
            NotificationType::CommissionOverdueFollowUp->value,
        ];

        $businessPressure = DatabaseNotification::query()
            ->where('notifiable_id', $commission->business_user_id)
            ->get()
            ->filter(fn ($n) => in_array($n->data['notification_type'] ?? null, $pressureTypes, true));

        $this->assertCount(5, $businessPressure);

        $followUps = $businessPressure->filter(
            fn ($n) => ($n->data['notification_type'] ?? null) === NotificationType::CommissionOverdueFollowUp->value,
        );
        $this->assertCount(2, $followUps);
    }

    // ── Eligibility ─────────────────────────────────────────────────────

    public function test_paid_commission_does_not_receive_pressure_reminders(): void
    {
        $commission = $this->createSealedCommission();
        $commission->forceFill(['due_at' => now()->subDays(3)])->save();

        Sanctum::actingAs($commission->business);
        $this->postJson('/api/v1/commissions/'.$commission->id.'/mark-paid')->assertOk();

        Notification::fake();
        app(CommissionReminderService::class)->processReminders(now());

        Notification::assertNothingSent();
    }

    public function test_received_commission_does_not_receive_pressure_reminders(): void
    {
        $commission = $this->createSealedCommission();
        $commission->forceFill(['due_at' => now()->subDays(3)])->save();

        Sanctum::actingAs($commission->business);
        $this->postJson('/api/v1/commissions/'.$commission->id.'/mark-paid')->assertOk();
        Sanctum::actingAs($commission->ambassador);
        $this->postJson('/api/v1/commissions/'.$commission->id.'/confirm-received')->assertOk();

        Notification::fake();
        app(CommissionReminderService::class)->processReminders(now());

        Notification::assertNothingSent();
    }

    // ── Idempotency ─────────────────────────────────────────────────────

    public function test_duplicate_scheduler_run_does_not_duplicate_reminders(): void
    {
        $commission = $this->createSealedCommission();
        $commission->forceFill(['due_at' => now()->subDays(2)])->save();

        $service = app(CommissionReminderService::class);
        $service->processReminders(now());
        $service->processReminders(now());

        $count = DatabaseNotification::query()
            ->where('notifiable_id', $commission->business_user_id)
            ->where('idempotency_key', 'like', 'commission:'.$commission->id.':reminder:%')
            ->count();

        $firstRunKeys = DatabaseNotification::query()
            ->where('notifiable_id', $commission->business_user_id)
            ->pluck('idempotency_key')
            ->unique()
            ->count();

        $this->assertSame($firstRunKeys, $count);
        $this->assertGreaterThan(0, $count);
    }

    // ── Recipients ──────────────────────────────────────────────────────

    public function test_ambassador_gets_due_and_single_overdue_not_follow_ups(): void
    {
        $commission = $this->createSealedCommission();
        // Sealing already sent commission_due to both parties.
        $dueCount = DatabaseNotification::query()
            ->where('notifiable_id', $commission->ambassador_user_id)
            ->get()
            ->filter(fn ($n) => ($n->data['notification_type'] ?? null) === NotificationType::CommissionDue->value)
            ->count();
        $this->assertSame(1, $dueCount);

        $commission->forceFill(['due_at' => now()->subDays(7)])->save();
        app(CommissionReminderService::class)->processReminders(now());

        $ambassador = DatabaseNotification::query()
            ->where('notifiable_id', $commission->ambassador_user_id)
            ->get();

        $overdue = $ambassador->filter(
            fn ($n) => ($n->data['notification_type'] ?? null) === NotificationType::CommissionOverdue->value,
        );
        $this->assertCount(1, $overdue);

        $followUps = $ambassador->filter(
            fn ($n) => ($n->data['notification_type'] ?? null) === NotificationType::CommissionOverdueFollowUp->value,
        );
        $this->assertCount(0, $followUps);

        $pressure = $ambassador->filter(
            fn ($n) => in_array($n->data['notification_type'] ?? null, [
                NotificationType::CommissionPreDeadline->value,
                NotificationType::CommissionDeadline->value,
            ], true),
        );
        $this->assertCount(0, $pressure);
    }

    public function test_ambassador_receives_paid_status_notification(): void
    {
        $commission = $this->createSealedCommission();

        Sanctum::actingAs($commission->business);
        $this->postJson('/api/v1/commissions/'.$commission->id.'/mark-paid')->assertOk();

        $paid = DatabaseNotification::query()
            ->where('notifiable_id', $commission->ambassador_user_id)
            ->get()
            ->filter(fn ($n) => ($n->data['notification_type'] ?? null) === NotificationType::CommissionPaid->value);

        $this->assertCount(1, $paid);
    }

    public function test_business_receives_received_status_notification(): void
    {
        $commission = $this->createSealedCommission();

        Sanctum::actingAs($commission->business);
        $this->postJson('/api/v1/commissions/'.$commission->id.'/mark-paid')->assertOk();
        Sanctum::actingAs($commission->ambassador);
        $this->postJson('/api/v1/commissions/'.$commission->id.'/confirm-received')->assertOk();

        $received = DatabaseNotification::query()
            ->where('notifiable_id', $commission->business_user_id)
            ->get()
            ->filter(fn ($n) => ($n->data['notification_type'] ?? null) === NotificationType::CommissionReceived->value);

        $this->assertCount(1, $received);
    }

    // ── Channels / payload ──────────────────────────────────────────────

    public function test_logical_notification_includes_safe_payload_without_secrets(): void
    {
        $commission = $this->createSealedCommission();
        $commission->forceFill(['due_at' => now()->subDay()])->save();

        app(CommissionReminderService::class)->processReminders(now());

        $record = DatabaseNotification::query()
            ->where('notifiable_id', $commission->business_user_id)
            ->latest('created_at')
            ->first();

        $this->assertNotNull($record);
        $data = $record->data;
        $this->assertArrayHasKey('commission_id', $data);
        $this->assertArrayHasKey('deal_id', $data);
        $this->assertArrayHasKey('amount', $data);
        $this->assertArrayHasKey('currency', $data);
        $this->assertArrayNotHasKey('payment_reference', $data);
        $this->assertArrayNotHasKey('payment_note', $data);
        $this->assertArrayNotHasKey('password', $data);
        $this->assertArrayNotHasKey('token', $data);
    }

    public function test_notification_fans_out_to_mail_and_database_channels(): void
    {
        $commission = $this->createSealedCommission();
        $notification = new CommissionNotification(
            $commission->id,
            NotificationType::CommissionDeadline,
            now()->toDateString(),
            $commission->business_user_id,
        );

        $channels = $notification->via($commission->business);
        $this->assertContains('database', $channels);
        $this->assertContains('mail', $channels);
        $this->assertNotNull($notification->toMail($commission->business));
    }

    // ── Account status ──────────────────────────────────────────────────

    public function test_suspended_and_banned_users_still_receive_transactional_notifications(): void
    {
        $commission = $this->createSealedCommission();
        $commission->business->forceFill(['status' => AccountStatus::Suspended])->save();
        $commission->ambassador->forceFill(['status' => AccountStatus::Banned])->save();
        $commission->forceFill(['due_at' => now()->subDay()])->save();

        app(CommissionReminderService::class)->processReminders(now());

        $this->assertTrue(
            DatabaseNotification::query()
                ->where('notifiable_id', $commission->business_user_id)
                ->exists(),
        );

        $ambassadorOverdue = DatabaseNotification::query()
            ->where('notifiable_id', $commission->ambassador_user_id)
            ->get()
            ->contains(fn ($n) => ($n->data['notification_type'] ?? null) === NotificationType::CommissionOverdue->value);
        $this->assertTrue($ambassadorOverdue);

        Sanctum::actingAs($commission->business->fresh());
        $this->getJson('/api/v1/notifications')->assertForbidden();
    }

    // ── Failure isolation / race ────────────────────────────────────────

    public function test_reminder_processing_does_not_mutate_commission_state(): void
    {
        $commission = $this->createSealedCommission();
        $commission->forceFill(['due_at' => now()->subDays(3)])->save();
        $snapshot = [
            'status' => $commission->status->value,
            'amount' => $commission->amount,
            'due_at' => $commission->due_at->toIso8601String(),
            'paid_at' => $commission->paid_at,
            'deal_status' => $commission->deal->status->value,
        ];

        app(CommissionReminderService::class)->processReminders(now());

        $fresh = $commission->fresh()->load('deal');
        $this->assertSame($snapshot['status'], $fresh->status->value);
        $this->assertSame($snapshot['amount'], $fresh->amount);
        $this->assertSame($snapshot['due_at'], $fresh->due_at->toIso8601String());
        $this->assertSame($snapshot['paid_at'], $fresh->paid_at);
        $this->assertSame($snapshot['deal_status'], $fresh->deal->status->value);
    }

    public function test_should_send_skips_when_commission_no_longer_due(): void
    {
        $commission = $this->createSealedCommission();
        $notification = new CommissionNotification(
            $commission->id,
            NotificationType::CommissionOverdue,
            now()->toDateString(),
            $commission->business_user_id,
        );

        Sanctum::actingAs($commission->business);
        $this->postJson('/api/v1/commissions/'.$commission->id.'/mark-paid')->assertOk();

        $this->assertFalse($notification->shouldSend($commission->business->fresh(), 'database'));
    }

    // ── Scheduler ───────────────────────────────────────────────────────

    public function test_artisan_reminder_command_runs(): void
    {
        $commission = $this->createSealedCommission();
        $commission->forceFill(['due_at' => now()->subDay()])->save();

        $this->artisan('commissions:process-reminders')->assertSuccessful();

        $this->assertTrue(
            DatabaseNotification::query()
                ->where('notifiable_id', $commission->business_user_id)
                ->exists(),
        );
    }

    public function test_slots_for_returns_exactly_five_business_slots(): void
    {
        $commission = $this->createSealedCommission();
        $slots = app(CommissionReminderService::class)->slotsFor($commission);

        $this->assertCount(5, $slots);
        $this->assertSame(-2, $slots[0]['offset_days']);
        $this->assertSame(0, $slots[1]['offset_days']);
        $this->assertSame(1, $slots[2]['offset_days']);
        $this->assertSame(4, $slots[3]['offset_days']);
        $this->assertSame(7, $slots[4]['offset_days']);
    }

    // ── Helper ──────────────────────────────────────────────────────────

    private function createSealedCommission(): Commission
    {
        $owner = User::factory()->business()->create();
        $campaign = Campaign::factory()->for($owner)->create([
            'title' => 'Reminder campaign',
            'status' => CampaignStatus::Active,
            'listing_starts_at' => now(),
            'listing_expires_at' => now()->addDays(30),
        ]);
        $version = CampaignVersion::factory()->for($campaign)->published()->create();
        $campaign->forceFill(['current_campaign_version_id' => $version->id])->save();

        $ambassador = User::factory()->ambassador()->create();
        Sanctum::actingAs($ambassador);
        $dealId = $this->postJson('/api/v1/deals', [
            'campaign_id' => $campaign->id,
        ])->assertCreated()->json('data.id');

        $this->post('/api/v1/deals/'.$dealId.'/payment-evidence', [
            'kind' => PaymentEvidenceKind::Receipt->value,
            'file' => UploadedFile::fake()->image('receipt.png'),
        ], ['Accept' => 'application/json'])->assertCreated();

        $deal = Deal::query()->with(['business', 'ambassador'])->findOrFail($dealId);
        Sanctum::actingAs($deal->business);
        $this->postJson('/api/v1/deals/'.$deal->id.'/confirm', [
            'confirmed_payment_amount' => 100000,
        ])->assertOk();

        return Commission::query()
            ->where('deal_id', $deal->id)
            ->with(['business', 'ambassador', 'deal'])
            ->firstOrFail();
    }
}
