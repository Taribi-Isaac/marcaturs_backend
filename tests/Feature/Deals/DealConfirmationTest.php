<?php

namespace Tests\Feature\Deals;

use App\Enums\AccountStatus;
use App\Enums\CampaignStatus;
use App\Enums\CommissionTrigger;
use App\Enums\CommissionType;
use App\Enums\DealEventType;
use App\Enums\DealStatus;
use App\Enums\PaymentEvidenceKind;
use App\Enums\PaymentEvidenceStatus;
use App\Models\Campaign;
use App\Models\CampaignVersion;
use App\Models\Deal;
use App\Models\DealEvent;
use App\Models\PaymentEvidence;
use App\Models\User;
use App\Support\Api\ApiErrorCode;
use App\Support\Money\DecimalMoney;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Tests\TestCase;

class DealConfirmationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake((string) config('deals.payment_evidence_disk'));
    }

    public function test_ambassador_and_admin_cannot_confirm(): void
    {
        [$deal, $ambassador] = $this->openDealWithEvidence();

        Sanctum::actingAs($ambassador);
        $this->postJson('/api/v1/deals/'.$deal->id.'/confirm', [
            'confirmed_payment_amount' => 100000,
        ])->assertStatus(403);

        Sanctum::actingAs(User::factory()->admin()->create());
        $this->postJson('/api/v1/deals/'.$deal->id.'/confirm', [
            'confirmed_payment_amount' => 100000,
        ])->assertStatus(403);

        $this->assertSame(DealStatus::PaymentPending, $deal->fresh()->status);
        $this->assertDatabaseCount('commissions', 0);
    }

    public function test_business_confirms_own_percentage_deal_and_calculates_server_side(): void
    {
        [$deal, $ambassador] = $this->openDealWithEvidence([
            'expected_transaction_amount' => '999999.00',
        ], [
            'amount' => '1.00',
        ]);
        $business = $deal->business;
        Sanctum::actingAs($business);

        $firstEvidenceId = PaymentEvidence::query()->where('deal_id', $deal->id)->value('id');

        Sanctum::actingAs($ambassador);
        $secondId = $this->postJson('/api/v1/deals/'.$deal->id.'/payment-evidence', [
            'kind' => PaymentEvidenceKind::TransactionReference->value,
            'reference_number' => 'REF-2',
        ])->assertCreated()->json('data.id');

        Sanctum::actingAs($business);
        $response = $this->postJson('/api/v1/deals/'.$deal->id.'/confirm', [
            'confirmed_payment_amount' => '100000.50',
            'commission_amount' => 1,
        ])->assertStatus(400);

        $response = $this->postJson('/api/v1/deals/'.$deal->id.'/confirm', [
            'confirmed_payment_amount' => '100000.50',
        ])->assertOk()
            ->assertJsonPath('data.status', DealStatus::Sealed->value)
            ->assertJsonPath('data.confirmed_payment_amount', '100000.50')
            ->assertJsonPath('data.commission_amount', DecimalMoney::percentageOf('100000.50', '10.00'))
            ->assertJsonPath('data.expected_transaction_amount', '999999.00');

        $this->assertNotNull($response->json('data.confirmed_at'));
        $this->assertNotSame('999999.00', $response->json('data.commission_amount'));
        $this->assertNotSame('1.00', $response->json('data.commission_amount'));
        $this->assertSame('10000.05', $response->json('data.commission_amount'));
        $this->assertNotNull($deal->fresh()->confirmed_at);

        $types = DealEvent::query()->where('deal_id', $deal->id)->orderBy('id')->pluck('type')->map->value->all();
        $this->assertContains(DealEventType::PaymentConfirmed->value, $types);
        $this->assertContains(DealEventType::DealSealed->value, $types);
        $this->assertContains(DealEventType::CommissionDue->value, $types);

        $confirmMeta = DealEvent::query()
            ->where('deal_id', $deal->id)
            ->where('type', DealEventType::PaymentConfirmed)
            ->firstOrFail()
            ->metadata;
        $this->assertEqualsCanonicalizing([$firstEvidenceId, $secondId], $confirmMeta['payment_evidence_ids']);
        $this->assertDatabaseCount('commissions', 1);
    }

    public function test_confirmation_requires_submitted_evidence(): void
    {
        [$deal] = $this->openDeal();
        Sanctum::actingAs($deal->business);

        $this->postJson('/api/v1/deals/'.$deal->id.'/confirm', [
            'confirmed_payment_amount' => 100000,
        ])->assertStatus(422)
            ->assertJsonPath('error.code', ApiErrorCode::BUSINESS_VALIDATION);

        $this->assertSame(DealStatus::PaymentPending, $deal->fresh()->status);
        $this->assertNull($deal->fresh()->confirmed_at);
    }

    public function test_transaction_reference_without_file_satisfies_evidence_requirement(): void
    {
        [$deal, $ambassador] = $this->openDeal();
        Sanctum::actingAs($ambassador);
        $this->postJson('/api/v1/deals/'.$deal->id.'/payment-evidence', [
            'kind' => PaymentEvidenceKind::TransactionReference->value,
            'reference_number' => 'TRX-9',
        ])->assertCreated();

        Sanctum::actingAs($deal->business);
        $this->postJson('/api/v1/deals/'.$deal->id.'/confirm', [
            'confirmed_payment_amount' => 50000,
        ])->assertOk()
            ->assertJsonPath('data.status', DealStatus::Sealed->value);
    }

    public function test_other_commission_trigger_cannot_be_confirmed(): void
    {
        [$deal] = $this->openDealWithEvidence([], [], [
            'commission_trigger' => CommissionTrigger::Other,
            'commission_trigger_description' => 'Custom published trigger',
        ]);
        Sanctum::actingAs($deal->business);

        $this->postJson('/api/v1/deals/'.$deal->id.'/confirm', [
            'confirmed_payment_amount' => 100000,
        ])->assertStatus(422);

        $this->assertSame(DealStatus::PaymentPending, $deal->fresh()->status);
    }

    public function test_fixed_commission_keeps_snapshot_amount(): void
    {
        [$deal] = $this->openDealWithEvidence([], [], [
            'commission_type' => CommissionType::Fixed,
            'commission_rate' => null,
            'commission_amount' => '7500.00',
        ]);
        Sanctum::actingAs($deal->business);

        $this->postJson('/api/v1/deals/'.$deal->id.'/confirm')
            ->assertOk()
            ->assertJsonPath('data.commission_amount', '7500.00')
            ->assertJsonPath('data.confirmed_payment_amount', null);
    }

    public function test_percentage_requires_confirmed_payment_amount(): void
    {
        [$deal] = $this->openDealWithEvidence();
        Sanctum::actingAs($deal->business);

        $this->postJson('/api/v1/deals/'.$deal->id.'/confirm', [])
            ->assertStatus(400)
            ->assertJsonPath('error.code', ApiErrorCode::VALIDATION_ERROR);
    }

    public function test_failed_event_write_rolls_back_confirmation(): void
    {
        [$deal] = $this->openDealWithEvidence();
        Sanctum::actingAs($deal->business);

        DealEvent::creating(function (DealEvent $event): void {
            if ($event->type === DealEventType::PaymentConfirmed) {
                throw new RuntimeException('event write failed');
            }
        });

        $this->postJson('/api/v1/deals/'.$deal->id.'/confirm', [
            'confirmed_payment_amount' => 100000,
        ])->assertStatus(500);

        $this->assertSame(DealStatus::PaymentPending, $deal->fresh()->status);
        $this->assertNull($deal->fresh()->confirmed_at);
        $this->assertNull($deal->fresh()->confirmed_payment_amount);
    }

    public function test_confirmation_is_idempotent_and_sealed_deal_cannot_be_rejected(): void
    {
        [$deal] = $this->openDealWithEvidence();
        $evidenceId = PaymentEvidence::query()->where('deal_id', $deal->id)->value('id');
        Sanctum::actingAs($deal->business);

        $this->postJson('/api/v1/deals/'.$deal->id.'/confirm', [
            'confirmed_payment_amount' => 100000,
        ])->assertOk();

        $eventCount = DealEvent::query()->where('deal_id', $deal->id)->count();
        $commission = $deal->fresh()->commission_amount;

        $this->postJson('/api/v1/deals/'.$deal->id.'/confirm', [
            'confirmed_payment_amount' => 1,
        ])->assertOk()
            ->assertJsonPath('data.status', DealStatus::Sealed->value)
            ->assertJsonPath('data.commission_amount', $commission)
            ->assertJsonPath('data.confirmed_payment_amount', '100000.00');

        $this->assertSame($eventCount, DealEvent::query()->where('deal_id', $deal->id)->count());
        $this->assertSame(1, DealEvent::query()->where('deal_id', $deal->id)->where('type', DealEventType::CommissionDue)->count());

        $this->postJson('/api/v1/deals/'.$deal->id.'/payment-evidence/'.$evidenceId.'/reject', [
            'reason' => 'Too late',
        ])->assertStatus(409)
            ->assertJsonPath('error.code', ApiErrorCode::CONFLICT);

        $this->assertSame(PaymentEvidenceStatus::Submitted, PaymentEvidence::query()->findOrFail($evidenceId)->status);
    }

    public function test_business_cannot_confirm_or_reject_another_business_deal(): void
    {
        [$deal] = $this->openDealWithEvidence();
        $evidenceId = PaymentEvidence::query()->where('deal_id', $deal->id)->value('id');
        Sanctum::actingAs(User::factory()->business()->create());

        $this->postJson('/api/v1/deals/'.$deal->id.'/confirm', [
            'confirmed_payment_amount' => 100000,
        ])->assertStatus(404);

        $this->postJson('/api/v1/deals/'.$deal->id.'/payment-evidence/'.$evidenceId.'/reject', [
            'reason' => 'Not my deal.',
        ])->assertStatus(404);
    }

    public function test_rejection_requires_reason_marks_evidence_and_allows_resubmit(): void
    {
        [$deal, $ambassador] = $this->openDealWithEvidence();
        $evidenceId = PaymentEvidence::query()->where('deal_id', $deal->id)->value('id');

        Sanctum::actingAs($deal->business);
        $this->postJson('/api/v1/deals/'.$deal->id.'/payment-evidence/'.$evidenceId.'/reject', [])
            ->assertStatus(400);

        $this->postJson('/api/v1/deals/'.$deal->id.'/payment-evidence/'.$evidenceId.'/reject', [
            'reason' => 'Payment not found on the business account.',
        ])->assertOk()
            ->assertJsonPath('data.status', PaymentEvidenceStatus::Rejected->value);

        $deal->refresh();
        $this->assertSame(DealStatus::PaymentPending, $deal->status);
        $this->assertNull($deal->confirmed_at);
        $this->assertTrue(
            DealEvent::query()
                ->where('deal_id', $deal->id)
                ->where('type', DealEventType::PaymentRejected)
                ->exists(),
        );
        $this->assertFalse(
            DealEvent::query()
                ->where('deal_id', $deal->id)
                ->where('type', DealEventType::CommissionDue)
                ->exists(),
        );

        Sanctum::actingAs($ambassador);
        $this->postJson('/api/v1/deals/'.$deal->id.'/payment-evidence', [
            'kind' => PaymentEvidenceKind::TransactionReference->value,
            'reference_number' => 'NEW-1',
        ])->assertCreated();

        Sanctum::actingAs($deal->business);
        $this->postJson('/api/v1/deals/'.$deal->id.'/confirm', [
            'confirmed_payment_amount' => 80000,
        ])->assertOk()
            ->assertJsonPath('data.status', DealStatus::Sealed->value);
    }

    public function test_guest_restricted_and_banned_cannot_confirm(): void
    {
        [$deal] = $this->openDealWithEvidence();
        $business = $deal->business;

        $this->app['auth']->forgetGuards();
        $this->postJson('/api/v1/deals/'.$deal->id.'/confirm', [
            'confirmed_payment_amount' => 100000,
        ])->assertStatus(401);

        Sanctum::actingAs($business);
        $business->forceFill(['status' => AccountStatus::Restricted])->save();
        $this->postJson('/api/v1/deals/'.$deal->id.'/confirm', [
            'confirmed_payment_amount' => 100000,
        ])->assertStatus(403);

        $business->forceFill(['status' => AccountStatus::Suspended])->save();
        $this->postJson('/api/v1/deals/'.$deal->id.'/confirm', [
            'confirmed_payment_amount' => 100000,
        ])->assertStatus(403);

        $business->forceFill(['status' => AccountStatus::Banned])->save();
        $this->postJson('/api/v1/deals/'.$deal->id.'/confirm', [
            'confirmed_payment_amount' => 100000,
        ])->assertStatus(403);
    }

    /**
     * @param  array<string, mixed>  $dealAttributes
     * @param  array<string, mixed>  $evidenceAttributes
     * @param  array<string, mixed>  $versionAttributes
     * @return array{0: Deal, 1: User}
     */
    private function openDealWithEvidence(
        array $dealAttributes = [],
        array $evidenceAttributes = [],
        array $versionAttributes = [],
    ): array {
        [$deal, $ambassador] = $this->openDeal($dealAttributes, $versionAttributes);
        Sanctum::actingAs($ambassador);
        $this->post('/api/v1/deals/'.$deal->id.'/payment-evidence', array_merge([
            'kind' => PaymentEvidenceKind::Receipt->value,
            'file' => UploadedFile::fake()->image('receipt.png'),
            'amount' => '25000.00',
        ], $evidenceAttributes), ['Accept' => 'application/json'])->assertCreated();

        return [$deal->fresh(['business']), $ambassador];
    }

    /**
     * @param  array<string, mixed>  $dealAttributes
     * @param  array<string, mixed>  $versionAttributes
     * @return array{0: Deal, 1: User}
     */
    private function openDeal(array $dealAttributes = [], array $versionAttributes = []): array
    {
        $owner = User::factory()->business()->create();
        $campaign = Campaign::factory()->for($owner)->create([
            'title' => 'Eligible campaign',
            'status' => CampaignStatus::Active,
            'listing_starts_at' => now(),
            'listing_expires_at' => now()->addDays(30),
        ]);
        $version = CampaignVersion::factory()->for($campaign)->published()->create($versionAttributes);
        $campaign->forceFill(['current_campaign_version_id' => $version->id])->save();

        $ambassador = User::factory()->ambassador()->create();
        Sanctum::actingAs($ambassador);
        $dealId = $this->postJson('/api/v1/deals', array_merge([
            'campaign_id' => $campaign->id,
        ], $dealAttributes))->assertCreated()->json('data.id');

        return [Deal::query()->findOrFail($dealId), $ambassador];
    }
}
