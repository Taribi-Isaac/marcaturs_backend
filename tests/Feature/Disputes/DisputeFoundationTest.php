<?php

namespace Tests\Feature\Disputes;

use App\Enums\CampaignStatus;
use App\Enums\CommissionStatus;
use App\Enums\DealStatus;
use App\Enums\DisputeEventType;
use App\Enums\DisputeStatus;
use App\Enums\NotificationType;
use App\Enums\PaymentEvidenceKind;
use App\Models\Campaign;
use App\Models\CampaignVersion;
use App\Models\Commission;
use App\Models\Deal;
use App\Models\Dispute;
use App\Models\DisputeAttachment;
use App\Models\DisputeCategory;
use App\Models\DisputeEvent;
use App\Models\User;
use App\Notifications\DisputeNotification;
use App\Support\Api\ApiErrorCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DisputeFoundationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake((string) config('disputes.attachment_disk'));
        Storage::fake((string) config('deals.payment_evidence_disk'));
        Notification::fake();
    }

    public function test_business_and_ambassador_can_create_disputes_including_completed_deal(): void
    {
        [$deal] = $this->openSealedDeal();
        $categoryId = DisputeCategory::query()->where('code', 'unpaid_commission')->value('id');

        Sanctum::actingAs($deal->ambassador);
        $response = $this->postJson('/api/v1/deals/'.$deal->id.'/disputes', [
            'category_id' => $categoryId,
            'description' => 'Business has not paid the commission after the deadline.',
        ])->assertCreated()
            ->assertJsonPath('data.status', DisputeStatus::Submitted->value)
            ->assertJsonPath('data.deal_id', $deal->id);

        $this->assertNotNull($response->json('data.reference'));
        $this->assertStringStartsWith('MH-D-', (string) $response->json('data.reference'));
        $this->assertSame(DealStatus::Sealed, $deal->fresh()->status);

        Notification::assertSentTo(
            $deal->business,
            DisputeNotification::class,
            fn (DisputeNotification $notification) => $notification->notificationType() === NotificationType::DisputeOpened,
        );

        Sanctum::actingAs($deal->business);
        $this->postJson('/api/v1/commissions/'.Commission::query()->where('deal_id', $deal->id)->value('id').'/mark-paid')->assertOk();
        Sanctum::actingAs($deal->ambassador);
        $this->postJson('/api/v1/commissions/'.Commission::query()->where('deal_id', $deal->id)->value('id').'/confirm-received')->assertOk();
        $this->assertSame(DealStatus::Completed, $deal->fresh()->status);

        $this->postJson('/api/v1/deals/'.$deal->id.'/disputes', [
            'category_id' => DisputeCategory::query()->where('code', 'other')->value('id'),
            'description' => 'Post-completion conduct issue that needs investigation.',
        ])->assertCreated()
            ->assertJsonPath('data.status', DisputeStatus::Submitted->value);

        $this->assertSame(DealStatus::Completed, $deal->fresh()->status);
        $this->assertSame(2, Dispute::query()->where('deal_id', $deal->id)->count());
    }

    public function test_non_party_admin_and_validation_rules_for_create(): void
    {
        [$deal] = $this->openSealedDeal();
        $categoryId = DisputeCategory::query()->where('code', 'other')->value('id');
        $outsider = User::factory()->business()->create();
        $admin = User::factory()->admin()->create();

        Sanctum::actingAs($outsider);
        $this->postJson('/api/v1/deals/'.$deal->id.'/disputes', [
            'category_id' => $categoryId,
            'description' => 'This should not be allowed for outsiders.',
        ])->assertStatus(404);

        Sanctum::actingAs($admin);
        $this->postJson('/api/v1/deals/'.$deal->id.'/disputes', [
            'category_id' => $categoryId,
            'description' => 'Admin cannot create disputes in phase one.',
        ])->assertStatus(403);

        Sanctum::actingAs($deal->ambassador);
        $this->postJson('/api/v1/deals/'.$deal->id.'/disputes', [])
            ->assertStatus(400)
            ->assertJsonPath('error.code', ApiErrorCode::VALIDATION_ERROR);

        DisputeCategory::query()->whereKey($categoryId)->update(['is_active' => false]);
        $this->postJson('/api/v1/deals/'.$deal->id.'/disputes', [
            'category_id' => $categoryId,
            'description' => 'Inactive category should be rejected by the service.',
        ])->assertStatus(422)
            ->assertJsonPath('error.code', ApiErrorCode::BUSINESS_VALIDATION);
    }

    public function test_multiple_open_disputes_allowed_and_list_is_party_scoped(): void
    {
        [$deal] = $this->openSealedDeal();
        $categoryId = DisputeCategory::query()->where('code', 'campaign_abuse')->value('id');

        Sanctum::actingAs($deal->business);
        $this->postJson('/api/v1/deals/'.$deal->id.'/disputes', [
            'category_id' => $categoryId,
            'description' => 'First open dispute about campaign abuse by ambassador.',
        ])->assertCreated();
        $this->postJson('/api/v1/deals/'.$deal->id.'/disputes', [
            'category_id' => $categoryId,
            'description' => 'Second open dispute is also allowed for the same deal.',
        ])->assertCreated();

        $this->assertSame(2, Dispute::query()->where('deal_id', $deal->id)->where('status', DisputeStatus::Submitted)->count());

        $this->getJson('/api/v1/disputes')
            ->assertOk()
            ->assertJsonPath('meta.pagination.total', 2);

        $other = User::factory()->ambassador()->create();
        Sanctum::actingAs($other);
        $this->getJson('/api/v1/disputes')
            ->assertOk()
            ->assertJsonPath('meta.pagination.total', 0);
    }

    public function test_phase_one_state_machine_and_financial_invariants(): void
    {
        [$deal] = $this->openSealedDeal();
        $commission = Commission::query()->where('deal_id', $deal->id)->firstOrFail();
        $commissionAmount = $commission->amount;
        $categoryId = DisputeCategory::query()->where('code', 'unpaid_commission')->value('id');
        $admin = User::factory()->admin()->create();

        Sanctum::actingAs($deal->ambassador);
        $disputeId = $this->postJson('/api/v1/deals/'.$deal->id.'/disputes', [
            'category_id' => $categoryId,
            'description' => 'Commission remains unpaid past the settlement window.',
        ])->assertCreated()->json('data.id');

        Sanctum::actingAs($deal->business);
        $this->postJson('/api/v1/admin/disputes/'.$disputeId.'/start-review')->assertStatus(403);
        $this->postJson('/api/v1/disputes/'.$disputeId.'/start-review')->assertStatus(404);

        Sanctum::actingAs($admin);
        $this->postJson('/api/v1/admin/disputes/'.$disputeId.'/start-review', [
            'note' => 'Classified as non-payment.',
        ])->assertOk()->assertJsonPath('data.status', DisputeStatus::UnderReview->value);

        $this->postJson('/api/v1/admin/disputes/'.$disputeId.'/request-evidence', [])
            ->assertStatus(400);

        $this->postJson('/api/v1/admin/disputes/'.$disputeId.'/request-evidence', [
            'reason' => 'Please upload proof of bank transfer attempts.',
        ])->assertOk()->assertJsonPath('data.status', DisputeStatus::EvidenceRequested->value);

        Sanctum::actingAs($deal->ambassador);
        $this->post('/api/v1/disputes/'.$disputeId.'/attachments', [
            'file' => UploadedFile::fake()->create('proof.pdf', 100, 'application/pdf'),
            'note' => 'Transfer screenshot pack',
        ], ['Accept' => 'application/json'])->assertCreated();

        Sanctum::actingAs($admin);
        $this->postJson('/api/v1/admin/disputes/'.$disputeId.'/resume-review')->assertOk()
            ->assertJsonPath('data.status', DisputeStatus::UnderReview->value);
        $this->postJson('/api/v1/admin/disputes/'.$disputeId.'/mark-decision-pending')->assertOk()
            ->assertJsonPath('data.status', DisputeStatus::DecisionPending->value);

        $this->postJson('/api/v1/admin/disputes/'.$disputeId.'/resolve', [])
            ->assertStatus(400);

        $this->postJson('/api/v1/admin/disputes/'.$disputeId.'/resolve', [
            'decision_notes' => 'Business failed to pay within the published deadline.',
            'action_notes' => 'Warning recorded. No financial mutation authorized.',
        ])->assertOk()->assertJsonPath('data.status', DisputeStatus::Resolved->value);

        Notification::assertSentTo(
            $deal->ambassador,
            DisputeNotification::class,
            fn (DisputeNotification $notification) => $notification->notificationType() === NotificationType::DisputeResolved,
        );
        Notification::assertSentTo(
            $deal->business,
            DisputeNotification::class,
            fn (DisputeNotification $notification) => $notification->notificationType() === NotificationType::DisputeResolved,
        );

        $this->postJson('/api/v1/admin/disputes/'.$disputeId.'/close', [
            'note' => 'Case closed after warning.',
        ])->assertOk()->assertJsonPath('data.status', DisputeStatus::Closed->value);

        $this->postJson('/api/v1/admin/disputes/'.$disputeId.'/start-review')->assertStatus(422);
        $this->postJson('/api/v1/admin/disputes/'.$disputeId.'/close')->assertStatus(422);

        $this->assertSame(DealStatus::Sealed, $deal->fresh()->status);
        $this->assertSame(CommissionStatus::Due, $commission->fresh()->status);
        $this->assertSame($commissionAmount, $commission->fresh()->amount);

        $types = DisputeEvent::query()->where('dispute_id', $disputeId)->pluck('type')->map->value->all();
        $this->assertContains(DisputeEventType::Created->value, $types);
        $this->assertContains(DisputeEventType::ReviewStarted->value, $types);
        $this->assertContains(DisputeEventType::EvidenceRequested->value, $types);
        $this->assertContains(DisputeEventType::ReviewResumed->value, $types);
        $this->assertContains(DisputeEventType::DecisionPending->value, $types);
        $this->assertContains(DisputeEventType::Resolved->value, $types);
        $this->assertContains(DisputeEventType::Closed->value, $types);
    }

    public function test_settlement_continues_while_dispute_open_and_no_disputed_deal_status(): void
    {
        [$deal] = $this->openSealedDeal();
        $commission = Commission::query()->where('deal_id', $deal->id)->firstOrFail();
        $categoryId = DisputeCategory::query()->where('code', 'unpaid_commission')->value('id');

        Sanctum::actingAs($deal->ambassador);
        $this->postJson('/api/v1/deals/'.$deal->id.'/disputes', [
            'category_id' => $categoryId,
            'description' => 'Open dispute must not freeze settlement actions.',
        ])->assertCreated();

        Sanctum::actingAs($deal->business);
        $this->postJson('/api/v1/commissions/'.$commission->id.'/mark-paid')->assertOk();
        Sanctum::actingAs($deal->ambassador);
        $this->postJson('/api/v1/commissions/'.$commission->id.'/confirm-received')->assertOk();

        $this->assertSame(DealStatus::Completed, $deal->fresh()->status);
        $this->assertSame(CommissionStatus::Received, $commission->fresh()->status);
        $this->assertNull(DealStatus::tryFrom('disputed'));
    }

    public function test_attachments_authorization_types_and_status_gates(): void
    {
        [$deal] = $this->openSealedDeal();
        $admin = User::factory()->admin()->create();
        $categoryId = DisputeCategory::query()->where('code', 'fraudulent_evidence')->value('id');

        Sanctum::actingAs($deal->business);
        $disputeId = $this->postJson('/api/v1/deals/'.$deal->id.'/disputes', [
            'category_id' => $categoryId,
            'description' => 'Suspected fraudulent payment evidence on this deal.',
        ])->assertCreated()->json('data.id');

        $this->post('/api/v1/disputes/'.$disputeId.'/attachments', [
            'file' => UploadedFile::fake()->create('notes.exe', 100, 'application/octet-stream'),
        ], ['Accept' => 'application/json'])->assertStatus(400);

        $attachmentId = $this->post('/api/v1/disputes/'.$disputeId.'/attachments', [
            'file' => UploadedFile::fake()->image('shot.png'),
        ], ['Accept' => 'application/json'])->assertCreated()->json('data.id');

        $outsider = User::factory()->ambassador()->create();
        Sanctum::actingAs($outsider);
        $this->get('/api/v1/disputes/'.$disputeId.'/attachments/'.$attachmentId.'/download')
            ->assertStatus(404);

        Sanctum::actingAs($deal->ambassador);
        $this->get('/api/v1/disputes/'.$disputeId.'/attachments/'.$attachmentId.'/download')
            ->assertOk();

        Sanctum::actingAs($admin);
        $this->postJson('/api/v1/admin/disputes/'.$disputeId.'/start-review')->assertOk();
        $this->postJson('/api/v1/admin/disputes/'.$disputeId.'/mark-decision-pending')->assertOk();
        $this->postJson('/api/v1/admin/disputes/'.$disputeId.'/resolve', [
            'decision_notes' => 'Evidence was fraudulent.',
            'action_notes' => 'Ambassador warned. No financial rewrite.',
        ])->assertOk();

        Sanctum::actingAs($deal->business);
        $this->post('/api/v1/disputes/'.$disputeId.'/attachments', [
            'file' => UploadedFile::fake()->image('late.png'),
        ], ['Accept' => 'application/json'])->assertStatus(422);

        $this->assertSame(1, DisputeAttachment::query()->where('dispute_id', $disputeId)->count());
        $this->assertSame(DealStatus::Sealed, $deal->fresh()->status);
    }

    public function test_admin_show_includes_related_domain_and_idor_holds(): void
    {
        [$deal] = $this->openSealedDeal();
        $admin = User::factory()->admin()->create();
        $categoryId = DisputeCategory::query()->where('code', 'other')->value('id');

        Sanctum::actingAs($deal->ambassador);
        $disputeId = $this->postJson('/api/v1/deals/'.$deal->id.'/disputes', [
            'category_id' => $categoryId,
            'description' => 'Need admin review of the related commercial record.',
        ])->assertCreated()->json('data.id');

        Sanctum::actingAs($admin);
        $this->getJson('/api/v1/admin/disputes/'.$disputeId)
            ->assertOk()
            ->assertJsonPath('data.deal.id', $deal->id)
            ->assertJsonPath('data.reference', Dispute::query()->findOrFail($disputeId)->reference);

        $stranger = User::factory()->business()->create();
        Sanctum::actingAs($stranger);
        $this->getJson('/api/v1/disputes/'.$disputeId)->assertStatus(404);
    }

    /**
     * @return array{0: Deal}
     */
    private function openSealedDeal(): array
    {
        $owner = User::factory()->business()->create();
        $campaign = Campaign::factory()->for($owner)->create([
            'title' => 'Dispute campaign',
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
