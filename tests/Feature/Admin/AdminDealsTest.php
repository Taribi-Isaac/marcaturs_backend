<?php

namespace Tests\Feature\Admin;

use App\Enums\AccountStatus;
use App\Enums\CampaignStatus;
use App\Enums\CommissionStatus;
use App\Enums\DealStatus;
use App\Enums\DisputeStatus;
use App\Enums\PaymentEvidenceKind;
use App\Enums\PaymentEvidenceStatus;
use App\Models\Campaign;
use App\Models\CampaignVersion;
use App\Models\Commission;
use App\Models\Deal;
use App\Models\DealEvent;
use App\Models\Dispute;
use App\Models\DisputeCategory;
use App\Models\PaymentEvidence;
use App\Models\User;
use App\Services\Deals\PaymentEvidenceStore;
use App\Support\Api\ApiErrorCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminDealsTest extends TestCase
{
    use RefreshDatabase;

    public function test_unauthenticated_and_non_admin_cannot_access_admin_deals(): void
    {
        $deal = $this->makeDeal();

        $this->getJson('/api/v1/admin/deals')
            ->assertStatus(401)
            ->assertJsonPath('error.code', ApiErrorCode::UNAUTHENTICATED);

        Sanctum::actingAs(User::factory()->business()->create());
        $this->getJson('/api/v1/admin/deals')->assertStatus(403);
        $this->getJson('/api/v1/admin/deals/'.$deal->id)->assertStatus(403);

        Sanctum::actingAs(User::factory()->ambassador()->create());
        $this->getJson('/api/v1/admin/deals')->assertStatus(403);
        $this->getJson('/api/v1/admin/deals/'.$deal->id.'/payment-evidence/1/download')->assertStatus(403);
    }

    public function test_admin_can_list_deals_with_pagination_and_ordering(): void
    {
        $first = $this->makeDeal(['product_name' => 'First product']);
        $second = $this->makeDeal(['product_name' => 'Second product']);
        Sanctum::actingAs(User::factory()->admin()->create());

        $response = $this->getJson('/api/v1/admin/deals?per_page=15')
            ->assertOk()
            ->assertJsonPath('meta.pagination.per_page', 15)
            ->assertJsonPath('meta.pagination.total', 2);

        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertSame([$second->id, $first->id], $ids);

        $this->getJson('/api/v1/admin/deals?per_page=101')->assertStatus(400);
    }

    public function test_list_supports_status_and_search_filters(): void
    {
        $business = User::factory()->business()->create([
            'name' => 'Solar Biz Owner',
            'email' => 'solar.owner@example.com',
        ]);
        $ambassador = User::factory()->ambassador()->create([
            'name' => 'Ada Marketer',
            'email' => 'ada.marketer@example.com',
        ]);
        $campaign = Campaign::factory()->for($business)->create([
            'title' => 'West Africa Solar Program',
            'status' => CampaignStatus::Active,
        ]);
        $version = CampaignVersion::factory()->for($campaign)->published()->create([
            'payment_account_identifier' => 'SECRET-ACC-999',
            'payment_instructions' => 'secret instructions',
            'payment_contact' => 'secret@pay.test',
        ]);
        $campaign->forceFill(['current_campaign_version_id' => $version->id])->save();

        $deal = $this->makeDeal([
            'business_user_id' => $business->id,
            'ambassador_user_id' => $ambassador->id,
            'campaign_id' => $campaign->id,
            'campaign_version_id' => $version->id,
            'product_name' => 'Hybrid Inverter Kit',
            'status' => DealStatus::PaymentPending,
        ]);
        $this->makeEvidence($deal, $ambassador, [
            'reference_number' => 'DEMO-TXN-SEARCH-42',
        ]);
        $this->makeDeal(['status' => DealStatus::Cancelled, 'product_name' => 'Other']);

        Sanctum::actingAs(User::factory()->admin()->create());

        $this->getJson('/api/v1/admin/deals?status=payment_pending')
            ->assertOk()
            ->assertJsonPath('meta.pagination.total', 1)
            ->assertJsonPath('data.0.id', $deal->id);

        $this->getJson('/api/v1/admin/deals?status=not_a_status')
            ->assertStatus(400)
            ->assertJsonPath('error.code', ApiErrorCode::VALIDATION_ERROR);

        foreach ([
            (string) $deal->id,
            'Solar Biz',
            'solar.owner@example.com',
            'Ada Marketer',
            'ada.marketer@example.com',
            'West Africa Solar',
            'Hybrid Inverter',
            'DEMO-TXN-SEARCH-42',
        ] as $query) {
            $this->getJson('/api/v1/admin/deals?q='.urlencode($query))
                ->assertOk()
                ->assertJsonPath('meta.pagination.total', 1)
                ->assertJsonPath('data.0.id', $deal->id);
        }
    }

    public function test_list_supports_open_dispute_and_commission_filters(): void
    {
        $pending = $this->makeDeal(['status' => DealStatus::PaymentPending]);
        $due = $this->makeSealedDealWithCommission(CommissionStatus::Due, dueAt: now()->addDays(3));
        $overdue = $this->makeSealedDealWithCommission(CommissionStatus::Due, dueAt: now()->subDay());
        $paid = $this->makeSealedDealWithCommission(CommissionStatus::Paid, dueAt: now()->subDays(2));
        $received = $this->makeSealedDealWithCommission(CommissionStatus::Received, dueAt: now()->subDays(5));
        $this->makeOpenDispute($due);

        Sanctum::actingAs(User::factory()->admin()->create());

        $this->getJson('/api/v1/admin/deals?open_dispute=1')
            ->assertOk()
            ->assertJsonPath('meta.pagination.total', 1)
            ->assertJsonPath('data.0.id', $due->id);

        $this->getJson('/api/v1/admin/deals?commission_overdue=1')
            ->assertOk()
            ->assertJsonPath('meta.pagination.total', 1)
            ->assertJsonPath('data.0.id', $overdue->id);

        $this->getJson('/api/v1/admin/deals?commission_status=due')
            ->assertOk()
            ->assertJsonPath('meta.pagination.total', 2);

        $this->getJson('/api/v1/admin/deals?commission_status=paid')
            ->assertOk()
            ->assertJsonPath('meta.pagination.total', 1)
            ->assertJsonPath('data.0.id', $paid->id);

        $this->getJson('/api/v1/admin/deals?commission_status=received')
            ->assertOk()
            ->assertJsonPath('meta.pagination.total', 1)
            ->assertJsonPath('data.0.id', $received->id);

        $this->assertSame(0, collect(
            $this->getJson('/api/v1/admin/deals?commission_status=due')->json('data'),
        )->where('id', $pending->id)->count());
    }

    public function test_admin_can_show_deal_detail_with_snapshot_version_and_redaction(): void
    {
        $business = User::factory()->business()->create(['status' => AccountStatus::Restricted]);
        $ambassador = User::factory()->ambassador()->create(['status' => AccountStatus::Active]);
        $campaign = Campaign::factory()->for($business)->create(['status' => CampaignStatus::Active]);
        $version = CampaignVersion::factory()->for($campaign)->published()->create([
            'product_name' => 'Version Product',
            'payment_account_identifier' => 'ACCT-SHOULD-OMIT',
            'payment_instructions' => 'Wire secretly',
            'payment_contact' => 'pay@secret.test',
            'payment_destination_name' => 'Solar Ops Account',
            'payment_provider' => 'Bank transfer',
        ]);
        $campaign->forceFill(['current_campaign_version_id' => $version->id])->save();

        $deal = $this->makeDeal([
            'business_user_id' => $business->id,
            'ambassador_user_id' => $ambassador->id,
            'campaign_id' => $campaign->id,
            'campaign_version_id' => $version->id,
            'status' => DealStatus::Sealed,
            'product_name' => 'Deal Snapshot Product',
            'confirmed_payment_amount' => '150000.00',
            'confirmed_at' => now()->subDay(),
            'commission_amount' => '15000.00',
        ]);
        $evidence = $this->makeEvidence($deal, $ambassador, [
            'reference_number' => 'REF-DETAIL-1',
            'amount' => '150000.00',
        ]);
        $commission = $this->attachCommission($deal, CommissionStatus::Due, now()->addDay());
        $dispute = $this->makeOpenDispute($deal);
        $event = new DealEvent;
        $event->forceFill([
            'deal_id' => $deal->id,
            'actor_user_id' => $business->id,
            'type' => 'deal_sealed',
            'previous_status' => DealStatus::PaymentPending->value,
            'new_status' => DealStatus::Sealed->value,
            'metadata' => ['safe' => true],
        ])->save();

        Sanctum::actingAs(User::factory()->admin()->create());

        $response = $this->getJson('/api/v1/admin/deals/'.$deal->id)
            ->assertOk()
            ->assertJsonPath('data.id', $deal->id)
            ->assertJsonPath('data.status', DealStatus::Sealed->value)
            ->assertJsonPath('data.business.status', AccountStatus::Restricted->value)
            ->assertJsonPath('data.snapshot.product_name', 'Deal Snapshot Product')
            ->assertJsonPath('data.snapshot.confirmed_payment_amount', '150000.00')
            ->assertJsonPath('data.campaign_version.id', $version->id)
            ->assertJsonPath('data.campaign_version.payment_destination_name', 'Solar Ops Account')
            ->assertJsonPath('data.commission.id', $commission->id)
            ->assertJsonPath('data.open_dispute_count', 1)
            ->assertJsonPath('data.disputes.0.id', $dispute->id)
            ->assertJsonPath('data.payment_evidence.0.id', $evidence->id)
            ->assertJsonPath('data.events.0.type', 'deal_sealed');

        $content = $response->getContent();
        $this->assertStringNotContainsString('ACCT-SHOULD-OMIT', $content);
        $this->assertStringNotContainsString('Wire secretly', $content);
        $this->assertStringNotContainsString('pay@secret.test', $content);
        $this->assertStringNotContainsString('password', $content);
        $this->assertStringNotContainsString('"disk"', $content);
        $this->assertStringNotContainsString('"path"', $content);

        $this->getJson('/api/v1/admin/deals/999999')->assertStatus(404);
    }

    public function test_admin_can_download_payment_evidence_and_wrong_pairs_are_hidden(): void
    {
        Storage::fake((string) config('deals.payment_evidence_disk'));

        $deal = $this->makeDeal();
        $other = $this->makeDeal();
        $ambassador = $deal->ambassador;
        $file = UploadedFile::fake()->image('receipt.png');
        $stored = app(PaymentEvidenceStore::class)->store($deal, $file);
        $evidence = $this->makeEvidence($deal, $ambassador, [
            'disk' => $stored['disk'],
            'path' => $stored['path'],
            'original_filename' => $stored['original_filename'],
            'mime_type' => $stored['mime_type'],
            'size_bytes' => $stored['size_bytes'],
        ]);
        $orphan = $this->makeEvidence($other, $other->ambassador);

        Sanctum::actingAs(User::factory()->admin()->create());

        $this->get('/api/v1/admin/deals/'.$deal->id.'/payment-evidence/'.$evidence->id.'/download')
            ->assertOk();

        $this->getJson('/api/v1/admin/deals/'.$deal->id.'/payment-evidence/'.$orphan->id.'/download')
            ->assertStatus(404);

        $this->getJson('/api/v1/admin/deals/'.$deal->id.'/payment-evidence/'.$evidence->id.'/download')
            ->assertOk(); // metadata-only path still streams when file exists

        $noFile = $this->makeEvidence($deal, $ambassador, [
            'disk' => null,
            'path' => null,
            'reference_number' => 'NO-FILE',
        ]);
        $this->getJson('/api/v1/admin/deals/'.$deal->id.'/payment-evidence/'.$noFile->id.'/download')
            ->assertStatus(404);

        Sanctum::actingAs(User::factory()->business()->create());
        $this->getJson('/api/v1/admin/deals/'.$deal->id.'/payment-evidence/'.$evidence->id.'/download')
            ->assertStatus(403);
    }

    public function test_admin_deals_routes_are_read_only(): void
    {
        $mutationMethods = ['POST', 'PUT', 'PATCH', 'DELETE'];
        $paths = [
            'api/v1/admin/deals',
            'api/v1/admin/deals/{deal}',
            'api/v1/admin/deals/{deal}/confirm',
            'api/v1/admin/deals/{deal}/cancel',
        ];

        foreach ($paths as $path) {
            foreach ($mutationMethods as $method) {
                $matched = collect(Route::getRoutes())->first(
                    fn ($route) => in_array($method, $route->methods(), true)
                        && $route->uri() === $path,
                );
                $this->assertNull($matched, "Unexpected {$method} {$path}");
            }
        }
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function makeDeal(array $overrides = []): Deal
    {
        $business = isset($overrides['business_user_id'])
            ? User::query()->findOrFail($overrides['business_user_id'])
            : User::factory()->business()->create();
        $ambassador = isset($overrides['ambassador_user_id'])
            ? User::query()->findOrFail($overrides['ambassador_user_id'])
            : User::factory()->ambassador()->create();

        if (isset($overrides['campaign_id'], $overrides['campaign_version_id'])) {
            $campaignId = $overrides['campaign_id'];
            $versionId = $overrides['campaign_version_id'];
        } else {
            $campaign = Campaign::factory()->for($business)->create(['status' => CampaignStatus::Active]);
            $version = CampaignVersion::factory()->for($campaign)->published()->create();
            $campaign->forceFill(['current_campaign_version_id' => $version->id])->save();
            $campaignId = $campaign->id;
            $versionId = $version->id;
        }

        return Deal::factory()->create(array_merge([
            'business_user_id' => $business->id,
            'ambassador_user_id' => $ambassador->id,
            'campaign_id' => $campaignId,
            'campaign_version_id' => $versionId,
            'status' => DealStatus::PaymentPending,
            'product_name' => 'Example product',
            'commission_type' => 'percentage',
            'commission_rate' => '10.00',
            'commission_amount' => '10000.00',
            'commission_trigger' => 'payment_confirmation',
            'commission_payment_deadline_days' => 7,
        ], $overrides));
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function makeEvidence(Deal $deal, User $ambassador, array $overrides = []): PaymentEvidence
    {
        $evidence = new PaymentEvidence;
        $evidence->forceFill(array_merge([
            'deal_id' => $deal->id,
            'ambassador_user_id' => $ambassador->id,
            'kind' => PaymentEvidenceKind::Receipt->value,
            'status' => PaymentEvidenceStatus::Submitted->value,
            'reference_number' => 'REF-'.$deal->id,
            'amount' => '100000.00',
            'currency' => 'NGN',
            'paid_on' => now()->toDateString(),
            'note' => 'Transfer note',
            'submitted_at' => now(),
        ], $overrides))->save();

        return $evidence->fresh() ?? $evidence;
    }

    private function attachCommission(
        Deal $deal,
        CommissionStatus $status,
        $dueAt,
    ): Commission {
        $commission = new Commission;
        $commission->forceFill([
            'deal_id' => $deal->id,
            'business_user_id' => $deal->business_user_id,
            'ambassador_user_id' => $deal->ambassador_user_id,
            'campaign_version_id' => $deal->campaign_version_id,
            'commission_type' => 'percentage',
            'commission_rate' => '10.00',
            'amount' => '10000.00',
            'currency' => 'NGN',
            'status' => $status,
            'became_due_at' => now()->subDays(3),
            'due_at' => $dueAt,
            'paid_at' => $status === CommissionStatus::Due ? null : now()->subDay(),
            'received_at' => $status === CommissionStatus::Received ? now() : null,
        ])->save();

        return $commission;
    }

    private function makeSealedDealWithCommission(CommissionStatus $status, $dueAt): Deal
    {
        $deal = $this->makeDeal([
            'status' => DealStatus::Sealed,
            'confirmed_payment_amount' => '100000.00',
            'confirmed_at' => now()->subDays(5),
        ]);
        $this->attachCommission($deal, $status, $dueAt);

        return $deal;
    }

    private function makeOpenDispute(Deal $deal): Dispute
    {
        $categoryId = DisputeCategory::query()->where('code', 'other')->value('id');
        $dispute = new Dispute;
        $dispute->forceFill([
            'reference' => 'MH-D-ADMIN-DEAL-'.$deal->id,
            'deal_id' => $deal->id,
            'commission_id' => Commission::query()->where('deal_id', $deal->id)->value('id'),
            'category_id' => $categoryId,
            'reporter_user_id' => $deal->ambassador_user_id,
            'accused_user_id' => $deal->business_user_id,
            'description' => 'Open dispute for admin deals filters.',
            'status' => DisputeStatus::Submitted,
        ])->save();

        return $dispute;
    }
}
