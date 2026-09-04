<?php

namespace Tests\Feature\Deals;

use App\Enums\CampaignStatus;
use App\Enums\CommissionStatus;
use App\Enums\DealStatus;
use App\Enums\DisputeStatus;
use App\Enums\PaymentEvidenceKind;
use App\Models\Campaign;
use App\Models\CampaignVersion;
use App\Models\Commission;
use App\Models\Deal;
use App\Models\Dispute;
use App\Models\DisputeCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class OpenDisputeVisibilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake((string) config('disputes.attachment_disk'));
        Storage::fake((string) config('deals.payment_evidence_disk'));
    }

    public function test_deal_with_no_disputes_reports_zero_open(): void
    {
        [$deal] = $this->openSealedDeal();

        Sanctum::actingAs($deal->business);
        $this->getJson('/api/v1/deals/'.$deal->id)
            ->assertOk()
            ->assertJsonPath('data.has_open_dispute', false)
            ->assertJsonPath('data.open_dispute_count', 0)
            ->assertJsonPath('data.status', DealStatus::Sealed->value);
    }

    public function test_deal_with_only_resolved_or_closed_disputes_reports_zero_open(): void
    {
        [$deal] = $this->openSealedDeal();
        $this->seedDispute($deal, DisputeStatus::Resolved);
        $this->seedDispute($deal, DisputeStatus::Closed);

        Sanctum::actingAs($deal->ambassador);
        $this->getJson('/api/v1/deals/'.$deal->id)
            ->assertOk()
            ->assertJsonPath('data.has_open_dispute', false)
            ->assertJsonPath('data.open_dispute_count', 0);
    }

    public function test_deal_with_one_open_dispute(): void
    {
        [$deal] = $this->openSealedDeal();
        $this->seedDispute($deal, DisputeStatus::Submitted);

        Sanctum::actingAs($deal->business);
        $this->getJson('/api/v1/deals/'.$deal->id)
            ->assertOk()
            ->assertJsonPath('data.has_open_dispute', true)
            ->assertJsonPath('data.open_dispute_count', 1);
    }

    public function test_deal_with_multiple_open_disputes(): void
    {
        [$deal] = $this->openSealedDeal();
        $this->seedDispute($deal, DisputeStatus::Submitted);
        $this->seedDispute($deal, DisputeStatus::UnderReview);
        $this->seedDispute($deal, DisputeStatus::EvidenceRequested);
        $this->seedDispute($deal, DisputeStatus::DecisionPending);

        Sanctum::actingAs($deal->ambassador);
        $this->getJson('/api/v1/deals/'.$deal->id)
            ->assertOk()
            ->assertJsonPath('data.has_open_dispute', true)
            ->assertJsonPath('data.open_dispute_count', 4);
    }

    public function test_mixture_of_open_and_resolved_disputes(): void
    {
        [$deal] = $this->openSealedDeal();
        $this->seedDispute($deal, DisputeStatus::Submitted);
        $this->seedDispute($deal, DisputeStatus::UnderReview);
        $this->seedDispute($deal, DisputeStatus::Resolved);
        $this->seedDispute($deal, DisputeStatus::Closed);

        Sanctum::actingAs($deal->business);
        $this->getJson('/api/v1/deals/'.$deal->id)
            ->assertOk()
            ->assertJsonPath('data.has_open_dispute', true)
            ->assertJsonPath('data.open_dispute_count', 2);
    }

    public function test_business_and_ambassador_deal_lists_expose_derived_fields(): void
    {
        [$deal] = $this->openSealedDeal();
        $this->seedDispute($deal, DisputeStatus::Submitted);
        $this->seedDispute($deal, DisputeStatus::Resolved);

        Sanctum::actingAs($deal->business);
        $this->getJson('/api/v1/deals')
            ->assertOk()
            ->assertJsonPath('data.0.id', $deal->id)
            ->assertJsonPath('data.0.has_open_dispute', true)
            ->assertJsonPath('data.0.open_dispute_count', 1);

        Sanctum::actingAs($deal->ambassador);
        $this->getJson('/api/v1/deals')
            ->assertOk()
            ->assertJsonPath('data.0.id', $deal->id)
            ->assertJsonPath('data.0.has_open_dispute', true)
            ->assertJsonPath('data.0.open_dispute_count', 1);
    }

    public function test_cross_party_deal_access_remains_denied(): void
    {
        [$deal] = $this->openSealedDeal();
        $this->seedDispute($deal, DisputeStatus::Submitted);
        $stranger = User::factory()->business()->create();

        Sanctum::actingAs($stranger);
        $this->getJson('/api/v1/deals/'.$deal->id)->assertStatus(404);
        $this->getJson('/api/v1/deals')
            ->assertOk()
            ->assertJsonPath('meta.pagination.total', 0);
    }

    public function test_open_dispute_does_not_change_deal_or_commission_state(): void
    {
        [$deal] = $this->openSealedDeal();
        $commission = Commission::query()->where('deal_id', $deal->id)->firstOrFail();
        $amount = $commission->amount;
        $dueAt = $commission->due_at?->toIso8601String();

        $this->seedDispute($deal, DisputeStatus::UnderReview);
        $this->seedDispute($deal, DisputeStatus::DecisionPending);

        $deal->refresh();
        $commission->refresh();

        $this->assertSame(DealStatus::Sealed, $deal->status);
        $this->assertSame(CommissionStatus::Due, $commission->status);
        $this->assertSame($amount, $commission->amount);
        $this->assertSame($dueAt, $commission->due_at?->toIso8601String());

        Sanctum::actingAs($deal->business);
        $this->getJson('/api/v1/deals/'.$deal->id)
            ->assertOk()
            ->assertJsonPath('data.status', DealStatus::Sealed->value)
            ->assertJsonPath('data.commission.status', CommissionStatus::Due->value)
            ->assertJsonPath('data.commission.amount', $amount)
            ->assertJsonPath('data.has_open_dispute', true)
            ->assertJsonPath('data.open_dispute_count', 2);
    }

    public function test_deal_list_avoids_per_deal_open_dispute_count_queries(): void
    {
        [$dealA] = $this->openSealedDeal();
        [$dealB] = $this->openSealedDeal($dealA->business);
        $this->seedDispute($dealA, DisputeStatus::Submitted);
        $this->seedDispute($dealB, DisputeStatus::UnderReview);
        $this->seedDispute($dealB, DisputeStatus::Resolved);

        $sqlLog = [];
        DB::listen(static function ($query) use (&$sqlLog): void {
            $sqlLog[] = $query->sql;
        });

        Sanctum::actingAs($dealA->business);
        $response = $this->getJson('/api/v1/deals')
            ->assertOk()
            ->assertJsonPath('meta.pagination.total', 2);

        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertContains($dealA->id, $ids);
        $this->assertContains($dealB->id, $ids);

        $perDealDisputeCountQueries = collect($sqlLog)->filter(static function (string $sql): bool {
            $normalized = strtolower($sql);
            $fromDisputes = str_contains($normalized, 'from "disputes"')
                || str_contains($normalized, 'from `disputes`');
            $fromDeals = str_contains($normalized, 'from "deals"')
                || str_contains($normalized, 'from `deals`');

            // Standalone count(*) FROM disputes WHERE deal_id = ? (N+1 pattern).
            return $fromDisputes
                && ! $fromDeals
                && str_contains($normalized, 'count(')
                && str_contains($normalized, 'deal_id');
        })->count();

        $this->assertSame(
            0,
            $perDealDisputeCountQueries,
            'Deal list must not issue a separate disputes count query per Deal.',
        );

        $listSelectsWithOpenCount = collect($sqlLog)->filter(static function (string $sql): bool {
            $normalized = strtolower($sql);
            $fromDeals = str_contains($normalized, 'from "deals"')
                || str_contains($normalized, 'from `deals`');

            return $fromDeals && str_contains($normalized, 'open_dispute_count');
        })->count();

        $this->assertGreaterThanOrEqual(1, $listSelectsWithOpenCount);
    }

    /**
     * @return array{0: Deal}
     */
    private function openSealedDeal(?User $owner = null): array
    {
        $owner ??= User::factory()->business()->create();
        $campaign = Campaign::factory()->for($owner)->create([
            'title' => 'Open dispute visibility campaign',
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

    private function seedDispute(Deal $deal, DisputeStatus $status): Dispute
    {
        $categoryId = DisputeCategory::query()->where('code', 'other')->value('id');

        $dispute = new Dispute;
        $dispute->forceFill([
            'reference' => 'MH-D-TEST-'.uniqid(),
            'deal_id' => $deal->id,
            'commission_id' => Commission::query()->where('deal_id', $deal->id)->value('id'),
            'category_id' => $categoryId,
            'reporter_user_id' => $deal->ambassador_user_id,
            'accused_user_id' => $deal->business_user_id,
            'status' => $status,
            'description' => 'Seeded dispute for open-visibility coverage.',
            'resolved_at' => $status === DisputeStatus::Resolved || $status === DisputeStatus::Closed ? now() : null,
            'closed_at' => $status === DisputeStatus::Closed ? now() : null,
        ])->save();

        return $dispute;
    }
}
