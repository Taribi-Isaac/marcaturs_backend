<?php

namespace Tests\Feature\Deals;

use App\Enums\AccountStatus;
use App\Enums\CampaignStatus;
use App\Enums\CommissionStatus;
use App\Enums\CommissionType;
use App\Enums\DealEventType;
use App\Enums\DealStatus;
use App\Enums\PaymentEvidenceKind;
use App\Models\Campaign;
use App\Models\CampaignVersion;
use App\Models\Commission;
use App\Models\Deal;
use App\Models\DealEvent;
use App\Models\PaymentEvidence;
use App\Models\User;
use App\Support\Money\CommissionDeadline;
use App\Support\Money\DecimalMoney;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Tests\TestCase;

class CommissionLiabilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake((string) config('deals.payment_evidence_disk'));
    }

    public function test_confirmation_creates_one_commission_matching_the_sealed_deal(): void
    {
        [$deal] = $this->openDealWithEvidence([
            'expected_transaction_amount' => '888888.00',
        ], [
            'amount' => '12.00',
        ]);
        Sanctum::actingAs($deal->business);

        $this->postJson('/api/v1/deals/'.$deal->id.'/confirm', [
            'confirmed_payment_amount' => '100000.50',
        ])->assertOk()
            ->assertJsonPath('data.commission.status', CommissionStatus::Due->value)
            ->assertJsonPath('data.commission.amount', '10000.05');

        $this->assertDatabaseCount('commissions', 1);
        $commission = Commission::query()->firstOrFail();
        $deal->refresh();

        $this->assertSame($deal->id, $commission->deal_id);
        $this->assertSame($deal->business_user_id, $commission->business_user_id);
        $this->assertSame($deal->ambassador_user_id, $commission->ambassador_user_id);
        $this->assertSame($deal->campaign_version_id, $commission->campaign_version_id);
        $this->assertSame(CommissionStatus::Due, $commission->status);
        $this->assertSame('10000.05', $commission->amount);
        $this->assertSame($deal->commission_amount, $commission->amount);
        $this->assertSame(DecimalMoney::percentageOf('100000.50', '10.00'), $commission->amount);
        $this->assertNotSame('888888.00', $commission->amount);
        $this->assertNotSame('12.00', $commission->amount);
        $this->assertSame($deal->price_currency, $commission->currency);
        $this->assertTrue($deal->confirmed_at->equalTo($commission->became_due_at));
        $this->assertTrue(
            CommissionDeadline::dueAt($deal)->equalTo($commission->due_at),
        );

        $this->assertSame(
            $commission->id,
            DealEvent::query()->where('deal_id', $deal->id)->where('type', DealEventType::CommissionDue)->value('metadata')['commission_id'],
        );
    }

    public function test_fixed_commission_copies_deal_amount_not_live_campaign_terms(): void
    {
        [$deal] = $this->openDealWithEvidence([], [], [
            'commission_type' => CommissionType::Fixed,
            'commission_rate' => null,
            'commission_amount' => '7500.00',
        ]);
        Sanctum::actingAs($deal->business);
        $this->postJson('/api/v1/deals/'.$deal->id.'/confirm')->assertOk();

        $deal->campaignVersion->forceFill(['commission_amount' => '1.00'])->save();

        $commission = Commission::query()->firstOrFail();
        $this->assertSame('7500.00', $commission->amount);
        $this->assertSame('7500.00', $deal->fresh()->commission_amount);
        $this->assertSame('1.00', $deal->campaignVersion->fresh()->commission_amount);
    }

    public function test_due_date_uses_seven_day_ceiling_and_stricter_version_days(): void
    {
        [$sevenDayDeal] = $this->openDealWithEvidence([], [], [
            'commission_payment_deadline_days' => 7,
        ]);
        Sanctum::actingAs($sevenDayDeal->business);
        $this->postJson('/api/v1/deals/'.$sevenDayDeal->id.'/confirm', [
            'confirmed_payment_amount' => 100000,
        ])->assertOk();
        $seven = Commission::query()->where('deal_id', $sevenDayDeal->id)->firstOrFail();
        $this->assertTrue($sevenDayDeal->fresh()->confirmed_at->copy()->addDays(7)->equalTo($seven->due_at));

        [$strictDeal] = $this->openDealWithEvidence([], [], [
            'commission_payment_deadline_days' => 3,
        ]);
        Sanctum::actingAs($strictDeal->business);
        $this->postJson('/api/v1/deals/'.$strictDeal->id.'/confirm', [
            'confirmed_payment_amount' => 100000,
        ])->assertOk();
        $strict = Commission::query()->where('deal_id', $strictDeal->id)->firstOrFail();
        $this->assertTrue($strictDeal->fresh()->confirmed_at->copy()->addDays(3)->equalTo($strict->due_at));

        [$longDeal] = $this->openDealWithEvidence([], [], [
            'commission_payment_deadline_days' => 14,
        ]);
        Sanctum::actingAs($longDeal->business);
        $this->postJson('/api/v1/deals/'.$longDeal->id.'/confirm', [
            'confirmed_payment_amount' => 100000,
        ])->assertOk();
        $long = Commission::query()->where('deal_id', $longDeal->id)->firstOrFail();
        $this->assertTrue($longDeal->fresh()->confirmed_at->copy()->addDays(7)->equalTo($long->due_at));
        $this->assertFalse($longDeal->fresh()->confirmed_at->copy()->addDays(14)->equalTo($long->due_at));
    }

    public function test_failed_commission_insert_rolls_back_seal(): void
    {
        [$deal] = $this->openDealWithEvidence();
        Sanctum::actingAs($deal->business);

        Commission::creating(function (): void {
            throw new RuntimeException('commission write failed');
        });

        $this->postJson('/api/v1/deals/'.$deal->id.'/confirm', [
            'confirmed_payment_amount' => 100000,
        ])->assertStatus(500);

        $this->assertSame(DealStatus::PaymentPending, $deal->fresh()->status);
        $this->assertDatabaseCount('commissions', 0);
        $this->assertFalse(
            DealEvent::query()->where('deal_id', $deal->id)->where('type', DealEventType::CommissionDue)->exists(),
        );
    }

    public function test_duplicate_confirmation_and_unique_deal_constraint(): void
    {
        [$deal] = $this->openDealWithEvidence();
        Sanctum::actingAs($deal->business);
        $this->postJson('/api/v1/deals/'.$deal->id.'/confirm', [
            'confirmed_payment_amount' => 100000,
        ])->assertOk();

        $this->postJson('/api/v1/deals/'.$deal->id.'/confirm', [
            'confirmed_payment_amount' => 1,
        ])->assertOk();

        $this->assertDatabaseCount('commissions', 1);

        $this->expectException(QueryException::class);
        $duplicate = new Commission;
        $duplicate->deal_id = $deal->id;
        $duplicate->business_user_id = $deal->business_user_id;
        $duplicate->ambassador_user_id = $deal->ambassador_user_id;
        $duplicate->campaign_version_id = $deal->campaign_version_id;
        $duplicate->status = CommissionStatus::Due;
        $duplicate->commission_type = CommissionType::Percentage;
        $duplicate->amount = '1.00';
        $duplicate->currency = 'NGN';
        $duplicate->became_due_at = now();
        $duplicate->due_at = now()->addDays(7);
        $duplicate->save();
    }

    public function test_multiple_evidence_still_one_commission_and_reject_creates_none(): void
    {
        [$deal, $ambassador] = $this->openDealWithEvidence();
        Sanctum::actingAs($ambassador);
        $this->postJson('/api/v1/deals/'.$deal->id.'/payment-evidence', [
            'kind' => PaymentEvidenceKind::TransactionReference->value,
            'reference_number' => 'TWO',
        ])->assertCreated();

        $evidenceId = PaymentEvidence::query()->where('deal_id', $deal->id)->value('id');
        Sanctum::actingAs($deal->business);
        $this->postJson('/api/v1/deals/'.$deal->id.'/payment-evidence/'.$evidenceId.'/reject', [
            'reason' => 'Not on the account.',
        ])->assertOk();
        $this->assertDatabaseCount('commissions', 0);

        Sanctum::actingAs($ambassador);
        $this->postJson('/api/v1/deals/'.$deal->id.'/payment-evidence', [
            'kind' => PaymentEvidenceKind::TransactionReference->value,
            'reference_number' => 'THREE',
        ])->assertCreated();

        Sanctum::actingAs($deal->business);
        $this->postJson('/api/v1/deals/'.$deal->id.'/confirm', [
            'confirmed_payment_amount' => 40000,
        ])->assertOk();

        $this->assertDatabaseCount('commissions', 1);
    }

    public function test_participants_can_read_own_commissions_and_cannot_mutate(): void
    {
        [$dealA] = $this->openDealWithEvidence();
        Sanctum::actingAs($dealA->business);
        $idA = $this->postJson('/api/v1/deals/'.$dealA->id.'/confirm', [
            'confirmed_payment_amount' => 100000,
        ])->json('data.commission.id');

        [$dealB] = $this->openDealWithEvidence();
        Sanctum::actingAs($dealB->business);
        $idB = $this->postJson('/api/v1/deals/'.$dealB->id.'/confirm', [
            'confirmed_payment_amount' => 50000,
        ])->json('data.commission.id');

        Sanctum::actingAs($dealA->business);
        $this->getJson('/api/v1/commissions')
            ->assertOk()
            ->assertJsonPath('data.0.id', $idA);
        $this->getJson('/api/v1/commissions/'.$idA)->assertOk();
        $this->getJson('/api/v1/commissions/'.$idB)->assertStatus(404);
        $this->patchJson('/api/v1/commissions/'.$idA, ['amount' => 1])->assertStatus(405);
        $this->postJson('/api/v1/commissions', [])->assertStatus(405);

        Sanctum::actingAs($dealA->ambassador);
        $this->getJson('/api/v1/commissions/'.$idA)->assertOk();
        $this->patchJson('/api/v1/commissions/'.$idA, ['amount' => 1])->assertStatus(405);

        Sanctum::actingAs($dealB->ambassador);
        $this->getJson('/api/v1/commissions/'.$idA)->assertStatus(404);

        Sanctum::actingAs(User::factory()->admin()->create());
        $this->getJson('/api/v1/commissions')->assertStatus(403);

        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/commissions')->assertStatus(401);

        Sanctum::actingAs($dealA->ambassador);
        $dealA->ambassador->forceFill(['status' => AccountStatus::Restricted])->save();
        $this->getJson('/api/v1/commissions')->assertStatus(403);
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

        $this->post('/api/v1/deals/'.$dealId.'/payment-evidence', array_merge([
            'kind' => PaymentEvidenceKind::Receipt->value,
            'file' => UploadedFile::fake()->image('receipt.png'),
        ], $evidenceAttributes), ['Accept' => 'application/json'])->assertCreated();

        return [Deal::query()->with(['business', 'ambassador', 'campaignVersion'])->findOrFail($dealId), $ambassador];
    }
}
