<?php

namespace Tests\Feature\Deals;

use App\Enums\AccountStatus;
use App\Enums\CampaignStatus;
use App\Enums\DealEventType;
use App\Enums\DealStatus;
use App\Enums\DisputeStatus;
use App\Enums\NotificationType;
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
use App\Notifications\DealCancelledNotification;
use App\Support\Api\ApiErrorCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DealCancellationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake((string) config('deals.payment_evidence_disk'));
        Storage::fake((string) config('disputes.attachment_disk'));
        Notification::fake();
    }

    public function test_business_and_ambassador_can_cancel_own_deal(): void
    {
        [$deal] = $this->openPaymentPendingDeal();

        Sanctum::actingAs($deal->business);
        $this->postJson('/api/v1/deals/'.$deal->id.'/cancel', [
            'reason' => 'Customer withdrew before payment.',
        ])->assertOk()
            ->assertJsonPath('data.status', DealStatus::Cancelled->value)
            ->assertJsonPath('data.has_open_dispute', false)
            ->assertJsonPath('data.open_dispute_count', 0);

        $this->assertNotNull($deal->fresh()->cancelled_at);
        $this->assertSame(1, DealEvent::query()->where('deal_id', $deal->id)->where('type', DealEventType::Cancelled)->count());

        [$dealB] = $this->openPaymentPendingDeal();
        Sanctum::actingAs($dealB->ambassador);
        $this->postJson('/api/v1/deals/'.$dealB->id.'/cancel', [
            'reason' => 'Customer withdrew before payment.',
        ])->assertOk()
            ->assertJsonPath('data.status', DealStatus::Cancelled->value);
    }

    public function test_unrelated_admin_and_guest_cannot_cancel(): void
    {
        [$deal] = $this->openPaymentPendingDeal();
        $payload = ['reason' => 'Should not be allowed for outsiders.'];

        Sanctum::actingAs(User::factory()->business()->create());
        $this->postJson('/api/v1/deals/'.$deal->id.'/cancel', $payload)->assertStatus(404);

        Sanctum::actingAs(User::factory()->admin()->create());
        $this->postJson('/api/v1/deals/'.$deal->id.'/cancel', $payload)->assertStatus(403);

        $this->app['auth']->forgetGuards();
        $this->postJson('/api/v1/deals/'.$deal->id.'/cancel', $payload)->assertStatus(401);

        $this->assertSame(DealStatus::PaymentPending, $deal->fresh()->status);
    }

    public function test_restricted_suspended_and_banned_cannot_cancel(): void
    {
        [$deal] = $this->openPaymentPendingDeal();
        $business = $deal->business;
        Sanctum::actingAs($business);

        $business->forceFill(['status' => AccountStatus::Restricted])->save();
        $this->postJson('/api/v1/deals/'.$deal->id.'/cancel', [
            'reason' => 'Restricted account should be blocked.',
        ])->assertStatus(403);

        $business->forceFill(['status' => AccountStatus::Suspended])->save();
        $this->postJson('/api/v1/deals/'.$deal->id.'/cancel', [
            'reason' => 'Suspended account should be blocked.',
        ])->assertStatus(403);

        $business->forceFill(['status' => AccountStatus::Banned])->save();
        $this->postJson('/api/v1/deals/'.$deal->id.'/cancel', [
            'reason' => 'Banned account should be blocked.',
        ])->assertStatus(403);

        $this->assertSame(DealStatus::PaymentPending, $deal->fresh()->status);
    }

    public function test_sealed_and_completed_cannot_be_cancelled(): void
    {
        [$deal] = $this->openPaymentPendingDeal();
        Sanctum::actingAs($deal->ambassador);
        $this->post('/api/v1/deals/'.$deal->id.'/payment-evidence', [
            'kind' => PaymentEvidenceKind::Receipt->value,
            'file' => UploadedFile::fake()->image('receipt.png'),
        ], ['Accept' => 'application/json'])->assertCreated();

        Sanctum::actingAs($deal->business);
        $this->postJson('/api/v1/deals/'.$deal->id.'/confirm', [
            'confirmed_payment_amount' => 100000,
        ])->assertOk();

        $this->postJson('/api/v1/deals/'.$deal->id.'/cancel', [
            'reason' => 'Too late after sealing.',
        ])->assertStatus(409)->assertJsonPath('error.code', ApiErrorCode::CONFLICT);

        $commissionId = Commission::query()->where('deal_id', $deal->id)->value('id');
        Sanctum::actingAs($deal->business);
        $this->postJson('/api/v1/commissions/'.$commissionId.'/mark-paid')->assertOk();
        Sanctum::actingAs($deal->ambassador);
        $this->postJson('/api/v1/commissions/'.$commissionId.'/confirm-received')->assertOk();
        $this->assertSame(DealStatus::Completed, $deal->fresh()->status);

        Sanctum::actingAs($deal->business);
        $this->postJson('/api/v1/deals/'.$deal->id.'/cancel', [
            'reason' => 'Completed deals must stay completed.',
        ])->assertStatus(409)->assertJsonPath('error.code', ApiErrorCode::CONFLICT);
    }

    public function test_idempotent_cancel_preserves_event_reason_timestamp_and_notifications(): void
    {
        [$deal] = $this->openPaymentPendingDeal();
        Sanctum::actingAs($deal->ambassador);

        $first = $this->postJson('/api/v1/deals/'.$deal->id.'/cancel', [
            'reason' => 'Original cancellation reason text.',
        ])->assertOk();

        $cancelledAt = $first->json('data.cancelled_at');
        $this->assertNotNull($cancelledAt);

        Notification::assertSentTo($deal->business, DealCancelledNotification::class);
        Notification::assertSentTo($deal->ambassador, DealCancelledNotification::class);
        Notification::assertSentToTimes($deal->business, DealCancelledNotification::class, 1);
        Notification::assertSentToTimes($deal->ambassador, DealCancelledNotification::class, 1);

        Notification::fake();

        $this->postJson('/api/v1/deals/'.$deal->id.'/cancel', [
            'reason' => 'Different reason on retry must be ignored.',
        ])->assertOk()
            ->assertJsonPath('data.status', DealStatus::Cancelled->value)
            ->assertJsonPath('data.cancelled_at', $cancelledAt);

        $this->assertSame(1, DealEvent::query()->where('deal_id', $deal->id)->where('type', DealEventType::Cancelled)->count());
        $event = DealEvent::query()->where('deal_id', $deal->id)->where('type', DealEventType::Cancelled)->firstOrFail();
        $this->assertSame('Original cancellation reason text.', $event->metadata['reason'] ?? null);
        Notification::assertNothingSent();
    }

    public function test_reason_validation_and_audit_metadata(): void
    {
        [$deal] = $this->openPaymentPendingDeal();
        Sanctum::actingAs($deal->business);

        $this->postJson('/api/v1/deals/'.$deal->id.'/cancel', [])
            ->assertStatus(400)
            ->assertJsonPath('error.code', ApiErrorCode::VALIDATION_ERROR);

        $this->postJson('/api/v1/deals/'.$deal->id.'/cancel', ['reason' => 'ab'])
            ->assertStatus(400)
            ->assertJsonPath('error.code', ApiErrorCode::VALIDATION_ERROR);

        $this->postJson('/api/v1/deals/'.$deal->id.'/cancel', [
            'reason' => 'Valid free-text cancellation reason.',
        ])->assertOk();

        $event = DealEvent::query()->where('deal_id', $deal->id)->where('type', DealEventType::Cancelled)->firstOrFail();
        $this->assertSame(DealStatus::PaymentPending, $event->previous_status);
        $this->assertSame(DealStatus::Cancelled, $event->new_status);
        $this->assertSame('Valid free-text cancellation reason.', $event->metadata['reason'] ?? null);
        $this->assertSame($deal->business_user_id, $event->actor_user_id);
    }

    public function test_cancel_with_and_without_evidence_retains_records_and_blocks_new_evidence(): void
    {
        [$deal] = $this->openPaymentPendingDeal();
        Sanctum::actingAs($deal->business);
        $this->postJson('/api/v1/deals/'.$deal->id.'/cancel', [
            'reason' => 'Cancelled before any evidence.',
        ])->assertOk();
        $this->assertDatabaseCount('payment_evidence', 0);

        [$dealB] = $this->openPaymentPendingDeal();
        Sanctum::actingAs($dealB->ambassador);
        $this->post('/api/v1/deals/'.$dealB->id.'/payment-evidence', [
            'kind' => PaymentEvidenceKind::Receipt->value,
            'file' => UploadedFile::fake()->image('one.png'),
        ], ['Accept' => 'application/json'])->assertCreated();
        $this->post('/api/v1/deals/'.$dealB->id.'/payment-evidence', [
            'kind' => PaymentEvidenceKind::TransactionReference->value,
            'reference_number' => 'REF-KEEP',
        ], ['Accept' => 'application/json'])->assertCreated();

        Sanctum::actingAs($dealB->business);
        $evidenceId = PaymentEvidence::query()->where('deal_id', $dealB->id)->orderBy('id')->value('id');
        $this->postJson('/api/v1/deals/'.$dealB->id.'/payment-evidence/'.$evidenceId.'/reject', [
            'reason' => 'Not found in bank account yet.',
        ])->assertOk();

        $this->postJson('/api/v1/deals/'.$dealB->id.'/cancel', [
            'reason' => 'Cancelled after mixed evidence states.',
        ])->assertOk();

        $this->assertSame(2, PaymentEvidence::query()->where('deal_id', $dealB->id)->count());
        $this->assertSame(
            PaymentEvidenceStatus::Rejected,
            PaymentEvidence::query()->whereKey($evidenceId)->firstOrFail()->status,
        );
        $this->assertSame(
            PaymentEvidenceStatus::Submitted,
            PaymentEvidence::query()->where('deal_id', $dealB->id)->where('id', '!=', $evidenceId)->firstOrFail()->status,
        );

        Sanctum::actingAs($dealB->ambassador);
        $this->post('/api/v1/deals/'.$dealB->id.'/payment-evidence', [
            'kind' => PaymentEvidenceKind::Receipt->value,
            'file' => UploadedFile::fake()->image('late.png'),
        ], ['Accept' => 'application/json'])
            ->assertStatus(409)
            ->assertJsonPath('error.code', ApiErrorCode::CONFLICT);
    }

    public function test_cancellation_creates_no_commission_and_leaves_financial_fields_null(): void
    {
        [$deal] = $this->openPaymentPendingDeal();
        Sanctum::actingAs($deal->ambassador);
        $this->postJson('/api/v1/deals/'.$deal->id.'/cancel', [
            'reason' => 'Abandoning before confirmation.',
        ])->assertOk();

        $fresh = $deal->fresh();
        $this->assertSame(DealStatus::Cancelled, $fresh->status);
        $this->assertNull($fresh->confirmed_at);
        $this->assertNull($fresh->confirmed_payment_amount);
        $this->assertDatabaseCount('commissions', 0);
        $this->assertNull(Commission::query()->where('deal_id', $deal->id)->first());
    }

    public function test_open_dispute_survives_cancellation_and_cancel_does_not_create_dispute(): void
    {
        [$deal] = $this->openPaymentPendingDeal();
        $categoryId = DisputeCategory::query()->where('code', 'other')->value('id');

        Sanctum::actingAs($deal->ambassador);
        $disputeId = $this->postJson('/api/v1/deals/'.$deal->id.'/disputes', [
            'category_id' => $categoryId,
            'description' => 'Open dispute should survive deal cancellation.',
        ])->assertCreated()->json('data.id');

        $this->postJson('/api/v1/deals/'.$deal->id.'/cancel', [
            'reason' => 'Cancel while dispute remains open.',
        ])->assertOk()
            ->assertJsonPath('data.status', DealStatus::Cancelled->value)
            ->assertJsonPath('data.has_open_dispute', true)
            ->assertJsonPath('data.open_dispute_count', 1);

        $dispute = Dispute::query()->findOrFail($disputeId);
        $this->assertSame(DisputeStatus::Submitted, $dispute->status);
        $this->assertSame(1, Dispute::query()->where('deal_id', $deal->id)->count());
    }

    public function test_both_parties_receive_in_app_and_mail_cancellation_notifications(): void
    {
        [$deal] = $this->openPaymentPendingDeal();
        Sanctum::actingAs($deal->business);
        $this->postJson('/api/v1/deals/'.$deal->id.'/cancel', [
            'reason' => 'Notify both parties of cancellation.',
        ])->assertOk();

        Notification::assertSentTo(
            $deal->business,
            DealCancelledNotification::class,
            function (DealCancelledNotification $notification) use ($deal): bool {
                return $notification->notificationType() === NotificationType::DealCancelled
                    && in_array('mail', $notification->via($deal->business), true)
                    && in_array('database', $notification->via($deal->business), true);
            },
        );
        Notification::assertSentTo(
            $deal->ambassador,
            DealCancelledNotification::class,
            function (DealCancelledNotification $notification) use ($deal): bool {
                return $notification->notificationType() === NotificationType::DealCancelled
                    && in_array('mail', $notification->via($deal->ambassador), true)
                    && in_array('database', $notification->via($deal->ambassador), true);
            },
        );
    }

    public function test_cancel_then_confirm_returns_conflict_and_list_show_expose_cancelled(): void
    {
        [$deal] = $this->openPaymentPendingDeal();
        Sanctum::actingAs($deal->ambassador);
        $this->post('/api/v1/deals/'.$deal->id.'/payment-evidence', [
            'kind' => PaymentEvidenceKind::Receipt->value,
            'file' => UploadedFile::fake()->image('receipt.png'),
        ], ['Accept' => 'application/json'])->assertCreated();

        Sanctum::actingAs($deal->business);
        $this->postJson('/api/v1/deals/'.$deal->id.'/cancel', [
            'reason' => 'Cancel wins before confirmation.',
        ])->assertOk();

        $this->postJson('/api/v1/deals/'.$deal->id.'/confirm', [
            'confirmed_payment_amount' => 100000,
        ])->assertStatus(409)->assertJsonPath('error.code', ApiErrorCode::CONFLICT);

        $this->getJson('/api/v1/deals/'.$deal->id)
            ->assertOk()
            ->assertJsonPath('data.status', DealStatus::Cancelled->value);

        $this->getJson('/api/v1/deals')
            ->assertOk()
            ->assertJsonPath('data.0.status', DealStatus::Cancelled->value);
    }

    public function test_confirm_then_cancel_returns_conflict(): void
    {
        [$deal] = $this->openPaymentPendingDeal();
        Sanctum::actingAs($deal->ambassador);
        $this->post('/api/v1/deals/'.$deal->id.'/payment-evidence', [
            'kind' => PaymentEvidenceKind::Receipt->value,
            'file' => UploadedFile::fake()->image('receipt.png'),
        ], ['Accept' => 'application/json'])->assertCreated();

        Sanctum::actingAs($deal->business);
        $this->postJson('/api/v1/deals/'.$deal->id.'/confirm', [
            'confirmed_payment_amount' => 100000,
        ])->assertOk();

        $this->postJson('/api/v1/deals/'.$deal->id.'/cancel', [
            'reason' => 'Confirm won first.',
        ])->assertStatus(409)->assertJsonPath('error.code', ApiErrorCode::CONFLICT);

        $this->assertSame(DealStatus::Sealed, $deal->fresh()->status);
        $this->assertDatabaseCount('commissions', 1);
    }

    /**
     * @return array{0: Deal}
     */
    private function openPaymentPendingDeal(): array
    {
        $owner = User::factory()->business()->create();
        $campaign = Campaign::factory()->for($owner)->create([
            'title' => 'Cancellation campaign',
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

        return [Deal::query()->with(['business', 'ambassador'])->findOrFail($dealId)];
    }
}
