<?php

namespace Tests\Feature\Deals;

use App\Enums\AccountStatus;
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
use App\Models\DealEvent;
use App\Models\PlatformPayment;
use App\Models\User;
use App\Support\Api\ApiErrorCode;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CommissionSettlementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake((string) config('deals.payment_evidence_disk'));
    }

    public function test_business_can_mark_own_due_commission_paid_without_mutating_liability(): void
    {
        [$deal] = $this->openSealedDeal();
        $commission = Commission::query()->where('deal_id', $deal->id)->firstOrFail();
        $amount = $commission->amount;
        $dueAt = $commission->due_at->toIso8601String();
        $dealStatus = $deal->status;
        $platformPayments = PlatformPayment::query()->count();
        $dealEventCount = DealEvent::query()->where('deal_id', $deal->id)->count();

        Sanctum::actingAs($deal->business);
        $this->postJson('/api/v1/commissions/'.$commission->id.'/mark-paid', [
            'payment_reference' => 'TRF-1001',
            'payment_note' => 'Paid from business account.',
            'amount' => '1.00',
            'status' => 'received',
        ])->assertStatus(400)
            ->assertJsonPath('error.code', ApiErrorCode::VALIDATION_ERROR);

        $this->postJson('/api/v1/commissions/'.$commission->id.'/mark-paid', [
            'payment_reference' => 'TRF-1001',
            'payment_note' => 'Paid from business account.',
        ])->assertOk()
            ->assertJsonPath('data.status', CommissionStatus::Paid->value)
            ->assertJsonPath('data.amount', $amount)
            ->assertJsonPath('data.due_at', $dueAt)
            ->assertJsonPath('data.payment_reference', 'TRF-1001')
            ->assertJsonPath('data.payment_note', 'Paid from business account.')
            ->assertJsonPath('data.id', $commission->id);

        $commission->refresh();
        $this->assertSame(CommissionStatus::Paid, $commission->status);
        $this->assertNotNull($commission->paid_at);
        $this->assertNull($commission->received_at);
        $this->assertSame($amount, $commission->amount);
        $this->assertSame($dueAt, $commission->due_at->toIso8601String());
        $this->assertSame(1, CommissionEvent::query()->where('commission_id', $commission->id)->count());
        $this->assertSame(
            CommissionEventType::Paid,
            CommissionEvent::query()->where('commission_id', $commission->id)->firstOrFail()->type,
        );
        $this->assertSame($dealStatus, $deal->fresh()->status);
        $this->assertSame(DealStatus::Sealed, $deal->fresh()->status);
        $this->assertSame($dealEventCount, DealEvent::query()->where('deal_id', $deal->id)->count());
        $this->assertDatabaseCount('commissions', 1);
        $this->assertSame($platformPayments, PlatformPayment::query()->count());
    }

    public function test_mark_paid_authorization_and_account_access(): void
    {
        [$dealA] = $this->openSealedDeal();
        [$dealB] = $this->openSealedDeal();
        $commissionA = Commission::query()->where('deal_id', $dealA->id)->firstOrFail();
        $commissionB = Commission::query()->where('deal_id', $dealB->id)->firstOrFail();

        Sanctum::actingAs($dealB->business);
        $this->postJson('/api/v1/commissions/'.$commissionA->id.'/mark-paid')->assertStatus(404);

        Sanctum::actingAs($dealA->ambassador);
        $this->postJson('/api/v1/commissions/'.$commissionA->id.'/mark-paid')->assertStatus(403);

        Sanctum::actingAs(User::factory()->admin()->create());
        $this->postJson('/api/v1/commissions/'.$commissionA->id.'/mark-paid')->assertStatus(403);

        $this->app['auth']->forgetGuards();
        $this->postJson('/api/v1/commissions/'.$commissionA->id.'/mark-paid')->assertStatus(401);

        Sanctum::actingAs($dealA->business);
        $dealA->business->forceFill(['status' => AccountStatus::Restricted])->save();
        $this->postJson('/api/v1/commissions/'.$commissionA->id.'/mark-paid')->assertStatus(403);

        $dealA->business->forceFill(['status' => AccountStatus::Active])->save();
        Sanctum::actingAs($dealA->business);
        $this->postJson('/api/v1/commissions/'.$commissionB->id.'/mark-paid')->assertStatus(404);
    }

    public function test_late_payment_is_allowed_and_does_not_change_due_at(): void
    {
        [$deal] = $this->openSealedDeal();
        $commission = Commission::query()->where('deal_id', $deal->id)->firstOrFail();
        $originalDueAt = $commission->due_at->copy();
        $commission->forceFill(['due_at' => now()->subDays(2)])->save();
        $storedDueAt = $commission->fresh()->due_at->toIso8601String();

        Sanctum::actingAs($deal->business);
        $this->postJson('/api/v1/commissions/'.$commission->id.'/mark-paid')->assertOk()
            ->assertJsonPath('data.status', CommissionStatus::Paid->value)
            ->assertJsonPath('data.due_at', $storedDueAt);

        $this->assertSame($storedDueAt, $commission->fresh()->due_at->toIso8601String());
        $this->assertNotSame($originalDueAt->toIso8601String(), $storedDueAt);
        $this->assertTrue($commission->fresh()->paid_at->greaterThan($commission->fresh()->due_at));
        $this->assertSame(CommissionStatus::Paid, $commission->fresh()->status);
    }

    public function test_ambassador_can_confirm_own_paid_commission(): void
    {
        [$deal] = $this->openSealedDeal();
        $commission = Commission::query()->where('deal_id', $deal->id)->firstOrFail();
        $amount = $commission->amount;
        Sanctum::actingAs($deal->business);
        $this->postJson('/api/v1/commissions/'.$commission->id.'/mark-paid')->assertOk();

        Sanctum::actingAs($deal->ambassador);
        $this->postJson('/api/v1/commissions/'.$commission->id.'/confirm-received', [
            'amount' => '9.00',
        ])->assertStatus(400);

        $this->postJson('/api/v1/commissions/'.$commission->id.'/confirm-received')->assertOk()
            ->assertJsonPath('data.status', CommissionStatus::Received->value)
            ->assertJsonPath('data.amount', $amount);

        $commission->refresh();
        $this->assertSame(CommissionStatus::Received, $commission->status);
        $this->assertNotNull($commission->paid_at);
        $this->assertNotNull($commission->received_at);
        $this->assertSame($amount, $commission->amount);
        $this->assertSame(DealStatus::Sealed, $deal->fresh()->status);
        $this->assertSame(2, CommissionEvent::query()->where('commission_id', $commission->id)->count());
        $this->assertTrue(
            CommissionEvent::query()
                ->where('commission_id', $commission->id)
                ->where('type', CommissionEventType::Received)
                ->exists(),
        );
    }

    public function test_ambassador_cannot_confirm_due_and_business_cannot_confirm_received(): void
    {
        [$deal] = $this->openSealedDeal();
        $commission = Commission::query()->where('deal_id', $deal->id)->firstOrFail();

        Sanctum::actingAs($deal->ambassador);
        $this->postJson('/api/v1/commissions/'.$commission->id.'/confirm-received')
            ->assertStatus(422)
            ->assertJsonPath('error.code', ApiErrorCode::BUSINESS_VALIDATION);
        $this->assertSame(CommissionStatus::Due, $commission->fresh()->status);
        $this->assertSame(0, CommissionEvent::query()->where('commission_id', $commission->id)->count());

        Sanctum::actingAs($deal->business);
        $this->postJson('/api/v1/commissions/'.$commission->id.'/confirm-received')->assertStatus(403);

        $this->postJson('/api/v1/commissions/'.$commission->id.'/mark-paid')->assertOk();
        Sanctum::actingAs(User::factory()->admin()->create());
        $this->postJson('/api/v1/commissions/'.$commission->id.'/confirm-received')->assertStatus(403);
    }

    public function test_settlement_is_idempotent_and_unique_per_event_type(): void
    {
        [$deal] = $this->openSealedDeal();
        $commission = Commission::query()->where('deal_id', $deal->id)->firstOrFail();

        Sanctum::actingAs($deal->business);
        $this->postJson('/api/v1/commissions/'.$commission->id.'/mark-paid', [
            'payment_reference' => 'FIRST',
        ])->assertOk();
        $paidAt = $commission->fresh()->paid_at->toIso8601String();

        $this->postJson('/api/v1/commissions/'.$commission->id.'/mark-paid', [
            'payment_reference' => 'SECOND',
        ])->assertOk()
            ->assertJsonPath('data.status', CommissionStatus::Paid->value)
            ->assertJsonPath('data.payment_reference', 'FIRST')
            ->assertJsonPath('data.paid_at', $paidAt);
        $this->assertSame(1, CommissionEvent::query()->where('commission_id', $commission->id)->where('type', CommissionEventType::Paid)->count());

        $this->postJson('/api/v1/commissions/'.$commission->id.'/mark-paid')->assertOk();
        $this->assertSame(1, CommissionEvent::query()->where('commission_id', $commission->id)->count());

        Sanctum::actingAs($deal->ambassador);
        $this->postJson('/api/v1/commissions/'.$commission->id.'/confirm-received')->assertOk();
        $receivedAt = $commission->fresh()->received_at->toIso8601String();
        $this->postJson('/api/v1/commissions/'.$commission->id.'/confirm-received')->assertOk()
            ->assertJsonPath('data.status', CommissionStatus::Received->value)
            ->assertJsonPath('data.received_at', $receivedAt);
        $this->assertSame(1, CommissionEvent::query()->where('commission_id', $commission->id)->where('type', CommissionEventType::Received)->count());

        Sanctum::actingAs($deal->business);
        $this->postJson('/api/v1/commissions/'.$commission->id.'/mark-paid')
            ->assertStatus(409)
            ->assertJsonPath('error.code', ApiErrorCode::CONFLICT);

        $this->expectException(QueryException::class);
        $duplicate = new CommissionEvent;
        $duplicate->commission_id = $commission->id;
        $duplicate->actor_user_id = $deal->business_user_id;
        $duplicate->type = CommissionEventType::Paid;
        $duplicate->previous_status = CommissionStatus::Due;
        $duplicate->new_status = CommissionStatus::Paid;
        $duplicate->save();
    }

    public function test_restricted_ambassador_cannot_confirm_received(): void
    {
        [$deal] = $this->openSealedDeal();
        $commission = Commission::query()->where('deal_id', $deal->id)->firstOrFail();
        Sanctum::actingAs($deal->business);
        $this->postJson('/api/v1/commissions/'.$commission->id.'/mark-paid')->assertOk();

        Sanctum::actingAs($deal->ambassador);
        $deal->ambassador->forceFill(['status' => AccountStatus::Suspended])->save();
        $this->postJson('/api/v1/commissions/'.$commission->id.'/confirm-received')->assertStatus(403);
    }

    /**
     * @return array{0: Deal}
     */
    private function openSealedDeal(): array
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

        return [$deal->fresh(['business', 'ambassador'])];
    }
}
