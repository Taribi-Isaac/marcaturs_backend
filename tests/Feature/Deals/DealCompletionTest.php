<?php

namespace Tests\Feature\Deals;

use App\Enums\CampaignStatus;
use App\Enums\CommissionEventType;
use App\Enums\CommissionStatus;
use App\Enums\DealEventType;
use App\Enums\DealStatus;
use App\Enums\PaymentEvidenceKind;
use App\Models\Campaign;
use App\Models\CampaignVersion;
use App\Models\Commission;
use App\Models\CommissionEvent;
use App\Models\Deal;
use App\Models\DealEvent;
use App\Models\User;
use App\Services\Deals\CommissionSettlementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DealCompletionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake((string) config('deals.payment_evidence_disk'));
    }

    public function test_confirm_received_automatically_completes_sealed_deal(): void
    {
        [$deal, $commission] = $this->paidCommission();

        Sanctum::actingAs($deal->ambassador);
        $this->postJson('/api/v1/commissions/'.$commission->id.'/confirm-received')
            ->assertOk()
            ->assertJsonPath('data.status', CommissionStatus::Received->value);

        $deal->refresh();
        $this->assertSame(DealStatus::Completed, $deal->status);
        $this->assertSame(CommissionStatus::Received, $commission->fresh()->status);
    }

    public function test_completion_creates_exactly_one_deal_completed_event(): void
    {
        [$deal, $commission] = $this->paidCommission();

        Sanctum::actingAs($deal->ambassador);
        $this->postJson('/api/v1/commissions/'.$commission->id.'/confirm-received')->assertOk();

        $events = DealEvent::query()
            ->where('deal_id', $deal->id)
            ->where('type', DealEventType::Completed)
            ->get();

        $this->assertCount(1, $events);
        $event = $events->first();
        $this->assertSame(DealStatus::Sealed, $event->previous_status);
        $this->assertSame(DealStatus::Completed, $event->new_status);
        $this->assertSame($deal->ambassador_user_id, $event->actor_user_id);
        $this->assertSame($commission->id, $event->metadata['commission_id']);
        $this->assertArrayHasKey('received_at', $event->metadata);
        $this->assertArrayNotHasKey('payment_reference', $event->metadata);
        $this->assertArrayNotHasKey('payment_note', $event->metadata);
    }

    public function test_completed_status_appears_in_deal_list_and_show(): void
    {
        [$deal, $commission] = $this->paidCommission();

        Sanctum::actingAs($deal->ambassador);
        $this->postJson('/api/v1/commissions/'.$commission->id.'/confirm-received')->assertOk();

        $show = $this->getJson('/api/v1/deals/'.$deal->id)
            ->assertOk()
            ->assertJsonPath('data.status', DealStatus::Completed->value);

        $eventTypes = collect($show->json('data.events'))->pluck('type');
        $this->assertTrue($eventTypes->contains(DealEventType::Completed->value));

        $this->getJson('/api/v1/deals')
            ->assertOk()
            ->assertJsonPath('data.0.status', DealStatus::Completed->value);

        Sanctum::actingAs($deal->business);
        $this->getJson('/api/v1/deals/'.$deal->id)
            ->assertOk()
            ->assertJsonPath('data.status', DealStatus::Completed->value);
    }

    public function test_confirm_received_idempotent_does_not_duplicate_completion(): void
    {
        [$deal, $commission] = $this->paidCommission();

        Sanctum::actingAs($deal->ambassador);
        $this->postJson('/api/v1/commissions/'.$commission->id.'/confirm-received')->assertOk();

        $receivedAt = $commission->fresh()->received_at->toIso8601String();
        $completedEventId = DealEvent::query()
            ->where('deal_id', $deal->id)
            ->where('type', DealEventType::Completed)
            ->value('id');

        $this->postJson('/api/v1/commissions/'.$commission->id.'/confirm-received')
            ->assertOk()
            ->assertJsonPath('data.status', CommissionStatus::Received->value)
            ->assertJsonPath('data.received_at', $receivedAt);

        $this->assertSame($receivedAt, $commission->fresh()->received_at->toIso8601String());
        $this->assertSame(DealStatus::Completed, $deal->fresh()->status);
        $this->assertSame(1, CommissionEvent::query()
            ->where('commission_id', $commission->id)
            ->where('type', CommissionEventType::Received)
            ->count());
        $this->assertSame(1, DealEvent::query()
            ->where('deal_id', $deal->id)
            ->where('type', DealEventType::Completed)
            ->count());
        $this->assertSame($completedEventId, DealEvent::query()
            ->where('deal_id', $deal->id)
            ->where('type', DealEventType::Completed)
            ->value('id'));
    }

    public function test_payment_pending_cannot_transition_directly_to_completed(): void
    {
        $owner = User::factory()->business()->create();
        $campaign = Campaign::factory()->for($owner)->create([
            'title' => 'Pending campaign',
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

        $deal = Deal::query()->findOrFail($dealId);
        $this->assertSame(DealStatus::PaymentPending, $deal->status);
        $this->assertFalse($deal->status->isCompleted());
        $this->assertTrue($deal->status->allowsConfirmation());

        // Completion only runs from sealed Deals inside confirm-received; no completion API exists.
        $this->assertFalse(
            DealEvent::query()
                ->where('deal_id', $deal->id)
                ->where('type', DealEventType::Completed)
                ->exists(),
        );
    }

    public function test_transaction_failure_rolls_back_commission_and_deal_completion(): void
    {
        [$deal, $commission] = $this->paidCommission();
        $beforeDealEvents = DealEvent::query()->where('deal_id', $deal->id)->count();
        $beforeCommissionEvents = CommissionEvent::query()->where('commission_id', $commission->id)->count();

        DealEvent::creating(function (DealEvent $event): void {
            if ($event->type === DealEventType::Completed) {
                throw new \RuntimeException('deal_completed write failed');
            }
        });

        Sanctum::actingAs($deal->ambassador);
        $this->postJson('/api/v1/commissions/'.$commission->id.'/confirm-received')
            ->assertStatus(500);

        $this->assertSame(CommissionStatus::Paid, $commission->fresh()->status);
        $this->assertNull($commission->fresh()->received_at);
        $this->assertSame(DealStatus::Sealed, $deal->fresh()->status);
        $this->assertSame($beforeDealEvents, DealEvent::query()->where('deal_id', $deal->id)->count());
        $this->assertSame($beforeCommissionEvents, CommissionEvent::query()->where('commission_id', $commission->id)->count());
        $this->assertFalse(
            DealEvent::query()
                ->where('deal_id', $deal->id)
                ->where('type', DealEventType::Completed)
                ->exists(),
        );
        $this->assertFalse(
            CommissionEvent::query()
                ->where('commission_id', $commission->id)
                ->where('type', CommissionEventType::Received)
                ->exists(),
        );
    }

    public function test_overdue_commission_can_still_complete_deal(): void
    {
        [$lateDeal] = $this->openSealedDeal();
        $lateCommission = Commission::query()->where('deal_id', $lateDeal->id)->firstOrFail();
        $lateCommission->forceFill(['due_at' => now()->subDays(2)])->save();
        $this->assertTrue($lateCommission->fresh()->isOverdue());

        Sanctum::actingAs($lateDeal->business);
        $this->postJson('/api/v1/commissions/'.$lateCommission->id.'/mark-paid')->assertOk();
        Sanctum::actingAs($lateDeal->ambassador);
        $this->postJson('/api/v1/commissions/'.$lateCommission->id.'/confirm-received')->assertOk();

        $this->assertSame(DealStatus::Completed, $lateDeal->fresh()->status);
        $this->assertSame(CommissionStatus::Received, $lateCommission->fresh()->status);
        $this->assertFalse($lateCommission->fresh()->isOverdue());
    }

    public function test_business_cannot_confirm_received_and_cannot_complete_via_settlement(): void
    {
        [$deal, $commission] = $this->paidCommission();

        Sanctum::actingAs($deal->business);
        $this->postJson('/api/v1/commissions/'.$commission->id.'/confirm-received')->assertStatus(403);
        $this->assertSame(DealStatus::Sealed, $deal->fresh()->status);
        $this->assertSame(CommissionStatus::Paid, $commission->fresh()->status);
    }

    public function test_completed_deal_cannot_revert_to_sealed_or_payment_pending_via_confirm(): void
    {
        [$deal, $commission] = $this->paidCommission();

        Sanctum::actingAs($deal->ambassador);
        $this->postJson('/api/v1/commissions/'.$commission->id.'/confirm-received')->assertOk();
        $this->assertSame(DealStatus::Completed, $deal->fresh()->status);

        Sanctum::actingAs($deal->business);
        $this->postJson('/api/v1/deals/'.$deal->id.'/confirm', [
            'confirmed_payment_amount' => 100000,
        ])->assertStatus(409);

        $this->assertSame(DealStatus::Completed, $deal->fresh()->status);
        $this->assertSame(1, DealEvent::query()
            ->where('deal_id', $deal->id)
            ->where('type', DealEventType::Completed)
            ->count());
    }

    public function test_settlement_service_completes_deal_inside_same_operation(): void
    {
        [$deal, $commission] = $this->paidCommission();

        $updated = app(CommissionSettlementService::class)->confirmReceived(
            $deal->ambassador,
            $commission,
            [],
        );

        $this->assertSame(CommissionStatus::Received, $updated->status);
        $this->assertSame(DealStatus::Completed, $updated->deal->fresh()->status);
    }

    /**
     * @return array{0: Deal, 1: Commission}
     */
    private function paidCommission(): array
    {
        [$deal] = $this->openSealedDeal();
        $commission = Commission::query()->where('deal_id', $deal->id)->firstOrFail();

        Sanctum::actingAs($deal->business);
        $this->postJson('/api/v1/commissions/'.$commission->id.'/mark-paid')->assertOk();

        return [$deal->fresh(['business', 'ambassador']), $commission->fresh()];
    }

    /**
     * @return array{0: Deal}
     */
    private function openSealedDeal(): array
    {
        $owner = User::factory()->business()->create();
        $campaign = Campaign::factory()->for($owner)->create([
            'title' => 'Completion campaign',
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

        return [$deal->fresh(['business', 'ambassador'])];
    }
}
