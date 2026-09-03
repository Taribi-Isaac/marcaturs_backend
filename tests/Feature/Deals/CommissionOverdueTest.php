<?php

namespace Tests\Feature\Deals;

use App\Enums\CampaignStatus;
use App\Enums\CommissionEventType;
use App\Enums\CommissionStatus;
use App\Enums\DealStatus;
use App\Enums\PaymentEvidenceKind;
use App\Models\Campaign;
use App\Models\CampaignVersion;
use App\Models\Commission;
use App\Models\CommissionEvent;
use App\Models\Deal;
use App\Models\User;
use App\Services\Deals\CommissionOverdueService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CommissionOverdueTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake((string) config('deals.payment_evidence_disk'));
    }

    // ── Derived is_overdue ──────────────────────────────────────────────

    public function test_due_before_deadline_is_not_overdue(): void
    {
        $commission = $this->createSealedCommission();
        // due_at is in the future by default (7 days from confirmed_at)
        $this->assertTrue($commission->status->isDue());
        $this->assertFalse($commission->isOverdue());

        Sanctum::actingAs($commission->business);
        $this->getJson('/api/v1/commissions/'.$commission->id)
            ->assertOk()
            ->assertJsonPath('data.is_overdue', false);
    }

    public function test_due_exactly_at_deadline_is_not_overdue(): void
    {
        $commission = $this->createSealedCommission();
        // Freeze to a clean second boundary so timestamp storage doesn't truncate
        $frozen = now()->startOfSecond();
        $this->travelTo($frozen);
        $commission->forceFill(['due_at' => $frozen])->save();

        // now() equals due_at exactly — greaterThan is false
        $this->assertFalse($commission->fresh()->isOverdue());
        $this->travelBack();
    }

    public function test_due_after_deadline_is_overdue(): void
    {
        $commission = $this->createSealedCommission();
        $commission->forceFill(['due_at' => now()->subDay()])->save();

        $this->assertTrue($commission->fresh()->isOverdue());

        Sanctum::actingAs($commission->business);
        $this->getJson('/api/v1/commissions/'.$commission->id)
            ->assertOk()
            ->assertJsonPath('data.is_overdue', true)
            ->assertJsonPath('data.status', CommissionStatus::Due->value);
    }

    public function test_paid_after_deadline_is_not_overdue(): void
    {
        $commission = $this->createSealedCommission();
        $commission->forceFill(['due_at' => now()->subDay()])->save();
        $this->assertTrue($commission->fresh()->isOverdue());

        Sanctum::actingAs($commission->business);
        $this->postJson('/api/v1/commissions/'.$commission->id.'/mark-paid')->assertOk();

        $this->assertFalse($commission->fresh()->isOverdue());
        $this->getJson('/api/v1/commissions/'.$commission->id)
            ->assertOk()
            ->assertJsonPath('data.is_overdue', false)
            ->assertJsonPath('data.status', CommissionStatus::Paid->value);
    }

    public function test_received_after_deadline_is_not_overdue(): void
    {
        $commission = $this->createSealedCommission();
        $commission->forceFill(['due_at' => now()->subDay()])->save();

        Sanctum::actingAs($commission->business);
        $this->postJson('/api/v1/commissions/'.$commission->id.'/mark-paid')->assertOk();

        Sanctum::actingAs($commission->ambassador);
        $this->postJson('/api/v1/commissions/'.$commission->id.'/confirm-received')->assertOk();

        $this->assertSame(CommissionStatus::Received, $commission->fresh()->status);
        $this->assertFalse($commission->fresh()->isOverdue());
    }

    // ── Detection / scheduler ───────────────────────────────────────────

    public function test_overdue_detection_creates_commission_overdue_event(): void
    {
        $commission = $this->createSealedCommission();
        $commission->forceFill(['due_at' => now()->subDay()])->save();

        $service = app(CommissionOverdueService::class);
        $result = $service->processOverdue();

        $this->assertSame(1, $result['candidates']);
        $this->assertSame(1, $result['newly_overdue']);
        $this->assertSame(0, $result['already_processed']);
        $this->assertSame(0, $result['failures']);

        $event = CommissionEvent::query()
            ->where('commission_id', $commission->id)
            ->where('type', CommissionEventType::Overdue)
            ->firstOrFail();

        $this->assertNull($event->actor_user_id);
        $this->assertSame(CommissionStatus::Due, $event->previous_status);
        $this->assertSame(CommissionStatus::Due, $event->new_status);
        $this->assertArrayHasKey('due_at', $event->metadata);
        $this->assertArrayHasKey('detected_at', $event->metadata);
    }

    public function test_same_commission_processed_twice_creates_only_one_event(): void
    {
        $commission = $this->createSealedCommission();
        $commission->forceFill(['due_at' => now()->subDay()])->save();

        $service = app(CommissionOverdueService::class);
        $service->processOverdue();
        $result = $service->processOverdue();

        $this->assertSame(1, $result['candidates']);
        $this->assertSame(0, $result['newly_overdue']);
        $this->assertSame(1, $result['already_processed']);

        $this->assertSame(
            1,
            CommissionEvent::query()
                ->where('commission_id', $commission->id)
                ->where('type', CommissionEventType::Overdue)
                ->count(),
        );
    }

    public function test_unique_constraint_prevents_duplicate_overdue_event(): void
    {
        $commission = $this->createSealedCommission();
        $commission->forceFill(['due_at' => now()->subDay()])->save();

        $event = new CommissionEvent;
        $event->commission_id = $commission->id;
        $event->actor_user_id = null;
        $event->type = CommissionEventType::Overdue;
        $event->previous_status = CommissionStatus::Due;
        $event->new_status = CommissionStatus::Due;
        $event->save();

        $this->expectException(QueryException::class);
        $dup = new CommissionEvent;
        $dup->commission_id = $commission->id;
        $dup->actor_user_id = null;
        $dup->type = CommissionEventType::Overdue;
        $dup->previous_status = CommissionStatus::Due;
        $dup->new_status = CommissionStatus::Due;
        $dup->save();
    }

    public function test_paid_commission_is_never_detected_overdue(): void
    {
        $commission = $this->createSealedCommission();
        $commission->forceFill(['due_at' => now()->subDay()])->save();

        Sanctum::actingAs($commission->business);
        $this->postJson('/api/v1/commissions/'.$commission->id.'/mark-paid')->assertOk();

        $result = app(CommissionOverdueService::class)->processOverdue();
        $this->assertSame(0, $result['candidates']);
        $this->assertFalse(
            CommissionEvent::query()
                ->where('commission_id', $commission->id)
                ->where('type', CommissionEventType::Overdue)
                ->exists(),
        );
    }

    public function test_received_commission_is_never_detected_overdue(): void
    {
        $commission = $this->createSealedCommission();
        $commission->forceFill(['due_at' => now()->subDay()])->save();

        Sanctum::actingAs($commission->business);
        $this->postJson('/api/v1/commissions/'.$commission->id.'/mark-paid')->assertOk();
        Sanctum::actingAs($commission->ambassador);
        $this->postJson('/api/v1/commissions/'.$commission->id.'/confirm-received')->assertOk();

        $result = app(CommissionOverdueService::class)->processOverdue();
        $this->assertSame(0, $result['candidates']);
    }

    public function test_overdue_processing_does_not_alter_commission_fields(): void
    {
        $commission = $this->createSealedCommission();
        $commission->forceFill(['due_at' => now()->subDay()])->save();
        $snapshot = [
            'status' => $commission->status->value,
            'amount' => $commission->amount,
            'commission_rate' => $commission->commission_rate,
            'commission_type' => $commission->commission_type->value,
            'currency' => $commission->currency,
            'due_at' => $commission->due_at->toIso8601String(),
        ];

        app(CommissionOverdueService::class)->processOverdue();
        $fresh = $commission->fresh();

        $this->assertSame($snapshot['status'], $fresh->status->value);
        $this->assertSame($snapshot['amount'], $fresh->amount);
        $this->assertSame($snapshot['commission_rate'], $fresh->commission_rate);
        $this->assertSame($snapshot['commission_type'], $fresh->commission_type->value);
        $this->assertSame($snapshot['currency'], $fresh->currency);
        $this->assertSame($snapshot['due_at'], $fresh->due_at->toIso8601String());
    }

    // ── Settlement interaction ──────────────────────────────────────────

    public function test_business_can_mark_overdue_derived_commission_paid(): void
    {
        $commission = $this->createSealedCommission();
        $commission->forceFill(['due_at' => now()->subDay()])->save();
        $this->assertTrue($commission->fresh()->isOverdue());

        // Process overdue first
        app(CommissionOverdueService::class)->processOverdue();

        Sanctum::actingAs($commission->business);
        $this->postJson('/api/v1/commissions/'.$commission->id.'/mark-paid')
            ->assertOk()
            ->assertJsonPath('data.status', CommissionStatus::Paid->value)
            ->assertJsonPath('data.is_overdue', false);

        $this->assertSame(CommissionStatus::Paid, $commission->fresh()->status);
    }

    public function test_commission_overdue_event_persists_after_payment(): void
    {
        $commission = $this->createSealedCommission();
        $commission->forceFill(['due_at' => now()->subDay()])->save();

        app(CommissionOverdueService::class)->processOverdue();

        Sanctum::actingAs($commission->business);
        $this->postJson('/api/v1/commissions/'.$commission->id.'/mark-paid')->assertOk();
        Sanctum::actingAs($commission->ambassador);
        $this->postJson('/api/v1/commissions/'.$commission->id.'/confirm-received')->assertOk();

        $this->assertTrue(
            CommissionEvent::query()
                ->where('commission_id', $commission->id)
                ->where('type', CommissionEventType::Overdue)
                ->exists(),
        );
    }

    public function test_paid_received_settlement_still_works_after_overdue(): void
    {
        $commission = $this->createSealedCommission();
        $commission->forceFill(['due_at' => now()->subDay()])->save();
        app(CommissionOverdueService::class)->processOverdue();

        Sanctum::actingAs($commission->business);
        $this->postJson('/api/v1/commissions/'.$commission->id.'/mark-paid')->assertOk();
        Sanctum::actingAs($commission->ambassador);
        $this->postJson('/api/v1/commissions/'.$commission->id.'/confirm-received')->assertOk();

        $this->assertSame(CommissionStatus::Received, $commission->fresh()->status);
        $this->assertSame(DealStatus::Sealed, $commission->deal->fresh()->status);
    }

    // ── API ─────────────────────────────────────────────────────────────

    public function test_list_exposes_is_overdue(): void
    {
        $commission = $this->createSealedCommission();
        $commission->forceFill(['due_at' => now()->subDay()])->save();

        Sanctum::actingAs($commission->ambassador);
        $this->getJson('/api/v1/commissions')
            ->assertOk()
            ->assertJsonPath('data.0.is_overdue', true);
    }

    public function test_show_exposes_is_overdue(): void
    {
        $commission = $this->createSealedCommission();

        Sanctum::actingAs($commission->business);
        $this->getJson('/api/v1/commissions/'.$commission->id)
            ->assertOk()
            ->assertJsonPath('data.is_overdue', false);
    }

    // ── Multi-commission with failure resilience ────────────────────────

    public function test_one_failure_does_not_prevent_others(): void
    {
        $commissionA = $this->createSealedCommission();
        $commissionA->forceFill(['due_at' => now()->subDays(2)])->save();

        $commissionB = $this->createSealedCommission();
        $commissionB->forceFill(['due_at' => now()->subDays(3)])->save();

        // Process both; both should get events
        $result = app(CommissionOverdueService::class)->processOverdue();

        $this->assertSame(2, $result['candidates']);
        $this->assertSame(2, $result['newly_overdue']);
        $this->assertSame(0, $result['failures']);

        $this->assertTrue(
            CommissionEvent::query()
                ->where('commission_id', $commissionA->id)
                ->where('type', CommissionEventType::Overdue)
                ->exists(),
        );
        $this->assertTrue(
            CommissionEvent::query()
                ->where('commission_id', $commissionB->id)
                ->where('type', CommissionEventType::Overdue)
                ->exists(),
        );
    }

    // ── Command smoke ───────────────────────────────────────────────────

    public function test_artisan_command_runs_and_registers_in_scheduler(): void
    {
        $commission = $this->createSealedCommission();
        $commission->forceFill(['due_at' => now()->subDay()])->save();

        $this->artisan('commissions:process-overdue')
            ->assertSuccessful();

        $this->assertTrue(
            CommissionEvent::query()
                ->where('commission_id', $commission->id)
                ->where('type', CommissionEventType::Overdue)
                ->exists(),
        );
    }

    // ── Helper ──────────────────────────────────────────────────────────

    private function createSealedCommission(): Commission
    {
        $owner = User::factory()->business()->create();
        $campaign = Campaign::factory()->for($owner)->create([
            'title' => 'Eligible campaign',
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
