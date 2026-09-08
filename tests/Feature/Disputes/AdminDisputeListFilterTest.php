<?php

namespace Tests\Feature\Disputes;

use App\Enums\CampaignStatus;
use App\Enums\DisputeStatus;
use App\Enums\PaymentEvidenceKind;
use App\Models\Campaign;
use App\Models\CampaignVersion;
use App\Models\Commission;
use App\Models\Deal;
use App\Models\Dispute;
use App\Models\DisputeCategory;
use App\Models\User;
use App\Support\Api\ApiErrorCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminDisputeListFilterTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_list_disputes_without_status_filter(): void
    {
        [$deal] = $this->openSealedDeal();
        $this->seedDispute($deal, DisputeStatus::Submitted);
        $this->seedDispute($deal, DisputeStatus::UnderReview);
        $admin = User::factory()->admin()->create();

        Sanctum::actingAs($admin);
        $this->getJson('/api/v1/admin/disputes')
            ->assertOk()
            ->assertJsonPath('meta.pagination.total', 2)
            ->assertJsonCount(2, 'data');
    }

    public function test_business_cannot_list_admin_disputes(): void
    {
        [$deal] = $this->openSealedDeal();
        $this->seedDispute($deal, DisputeStatus::Submitted);

        Sanctum::actingAs($deal->business);
        $this->getJson('/api/v1/admin/disputes')->assertStatus(403);
    }

    public function test_ambassador_cannot_list_admin_disputes(): void
    {
        [$deal] = $this->openSealedDeal();
        $this->seedDispute($deal, DisputeStatus::Submitted);

        Sanctum::actingAs($deal->ambassador);
        $this->getJson('/api/v1/admin/disputes')->assertStatus(403);
    }

    public function test_unauthenticated_list_is_rejected(): void
    {
        $this->getJson('/api/v1/admin/disputes')->assertStatus(401);
    }

    public function test_valid_status_returns_only_matching_disputes(): void
    {
        [$deal] = $this->openSealedDeal();
        $submitted = $this->seedDispute($deal, DisputeStatus::Submitted);
        $this->seedDispute($deal, DisputeStatus::UnderReview);
        $this->seedDispute($deal, DisputeStatus::Resolved);
        $admin = User::factory()->admin()->create();

        Sanctum::actingAs($admin);
        $response = $this->getJson('/api/v1/admin/disputes?status=submitted')
            ->assertOk()
            ->assertJsonPath('meta.pagination.total', 1)
            ->assertJsonCount(1, 'data');

        $this->assertSame($submitted->id, $response->json('data.0.id'));
        $this->assertSame(DisputeStatus::Submitted->value, $response->json('data.0.status'));
    }

    public function test_each_valid_status_returns_its_own_dataset(): void
    {
        [$deal] = $this->openSealedDeal();
        $byStatus = [];
        foreach (DisputeStatus::cases() as $status) {
            $byStatus[$status->value] = $this->seedDispute($deal, $status);
        }
        $admin = User::factory()->admin()->create();

        Sanctum::actingAs($admin);
        foreach (DisputeStatus::cases() as $status) {
            $response = $this->getJson('/api/v1/admin/disputes?status='.$status->value)
                ->assertOk()
                ->assertJsonPath('meta.pagination.total', 1)
                ->assertJsonCount(1, 'data');

            $this->assertSame($byStatus[$status->value]->id, $response->json('data.0.id'));
            $this->assertSame($status->value, $response->json('data.0.status'));
        }
    }

    public function test_invalid_status_is_rejected(): void
    {
        $admin = User::factory()->admin()->create();

        Sanctum::actingAs($admin);
        $this->getJson('/api/v1/admin/disputes?status=not_a_real_status')
            ->assertStatus(400)
            ->assertJsonPath('error.code', ApiErrorCode::VALIDATION_ERROR);
    }

    public function test_status_filter_is_applied_before_pagination(): void
    {
        [$deal] = $this->openSealedDeal();
        $hidden = $this->seedDispute($deal, DisputeStatus::UnderReview);

        for ($i = 0; $i < 20; $i++) {
            $this->seedDispute($deal, DisputeStatus::Submitted);
        }

        $admin = User::factory()->admin()->create();
        Sanctum::actingAs($admin);

        $unfiltered = $this->getJson('/api/v1/admin/disputes')
            ->assertOk()
            ->assertJsonPath('meta.pagination.total', 21)
            ->assertJsonPath('meta.pagination.per_page', 20)
            ->assertJsonPath('meta.pagination.last_page', 2);

        $pageOneIds = collect($unfiltered->json('data'))->pluck('id')->all();
        $this->assertNotContains($hidden->id, $pageOneIds);

        $filtered = $this->getJson('/api/v1/admin/disputes?status=under_review')
            ->assertOk()
            ->assertJsonPath('meta.pagination.total', 1)
            ->assertJsonPath('meta.pagination.last_page', 1)
            ->assertJsonCount(1, 'data');

        $this->assertSame($hidden->id, $filtered->json('data.0.id'));

        $pageTwo = $this->getJson('/api/v1/admin/disputes?page=2')
            ->assertOk()
            ->assertJsonPath('meta.pagination.current_page', 2);

        $pageTwoIds = collect($pageTwo->json('data'))->pluck('id')->all();
        $this->assertContains($hidden->id, $pageTwoIds);
    }

    public function test_filtered_pagination_metadata_reflects_filtered_dataset(): void
    {
        [$deal] = $this->openSealedDeal();

        for ($i = 0; $i < 25; $i++) {
            $this->seedDispute($deal, DisputeStatus::Submitted);
        }
        for ($i = 0; $i < 3; $i++) {
            $this->seedDispute($deal, DisputeStatus::DecisionPending);
        }

        $admin = User::factory()->admin()->create();
        Sanctum::actingAs($admin);

        $this->getJson('/api/v1/admin/disputes?status=submitted')
            ->assertOk()
            ->assertJsonPath('meta.pagination.total', 25)
            ->assertJsonPath('meta.pagination.per_page', 20)
            ->assertJsonPath('meta.pagination.last_page', 2)
            ->assertJsonCount(20, 'data');

        $this->getJson('/api/v1/admin/disputes?status=submitted&page=2')
            ->assertOk()
            ->assertJsonPath('meta.pagination.current_page', 2)
            ->assertJsonPath('meta.pagination.total', 25)
            ->assertJsonCount(5, 'data');

        $this->getJson('/api/v1/admin/disputes?status=decision_pending')
            ->assertOk()
            ->assertJsonPath('meta.pagination.total', 3)
            ->assertJsonPath('meta.pagination.last_page', 1)
            ->assertJsonCount(3, 'data');
    }

    /**
     * @return array{0: Deal}
     */
    private function openSealedDeal(): array
    {
        $owner = User::factory()->business()->create();
        $campaign = Campaign::factory()->for($owner)->create([
            'title' => 'Admin dispute list campaign',
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
            'reference' => 'MH-D-LIST-'.uniqid(),
            'deal_id' => $deal->id,
            'commission_id' => Commission::query()->where('deal_id', $deal->id)->value('id'),
            'category_id' => $categoryId,
            'reporter_user_id' => $deal->ambassador_user_id,
            'accused_user_id' => $deal->business_user_id,
            'status' => $status,
            'description' => 'Seeded dispute for admin list filter coverage.',
            'resolved_at' => $status === DisputeStatus::Resolved || $status === DisputeStatus::Closed ? now() : null,
            'closed_at' => $status === DisputeStatus::Closed ? now() : null,
        ])->save();

        return $dispute;
    }
}
