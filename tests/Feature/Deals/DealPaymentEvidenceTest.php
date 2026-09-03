<?php

namespace Tests\Feature\Deals;

use App\Enums\AccountStatus;
use App\Enums\CampaignStatus;
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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Tests\TestCase;

class DealPaymentEvidenceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake((string) config('deals.payment_evidence_disk'));
    }

    public function test_ambassador_submits_file_evidence_without_changing_deal_status(): void
    {
        [$deal, $ambassador] = $this->openDeal();
        Sanctum::actingAs($ambassador);

        $file = UploadedFile::fake()->image('receipt.png');

        $response = $this->post('/api/v1/deals/'.$deal->id.'/payment-evidence', [
            'kind' => PaymentEvidenceKind::Receipt->value,
            'file' => $file,
            'reference_number' => 'TRF-1001',
            'amount' => 100000,
            'paid_on' => now()->toDateString(),
            'note' => 'Customer transferred to the published account.',
        ], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('data.deal_id', $deal->id)
            ->assertJsonPath('data.kind', PaymentEvidenceKind::Receipt->value)
            ->assertJsonPath('data.status', PaymentEvidenceStatus::Submitted->value)
            ->assertJsonPath('data.submitted_by.id', $ambassador->id)
            ->assertJsonPath('data.has_file', true)
            ->assertJsonPath('data.original_filename', 'receipt.png')
            ->assertJsonPath('data.amount', '100000.00')
            ->assertJsonMissingPath('data.path')
            ->assertJsonMissingPath('data.disk');

        $deal->refresh();
        $this->assertSame(DealStatus::PaymentPending, $deal->status);
        $this->assertDatabaseCount('commissions', 0);
        $this->assertDatabaseCount('payment_evidence', 1);
        $this->assertSame(
            DealEventType::PaymentEvidenceSubmitted,
            DealEvent::query()->where('deal_id', $deal->id)->orderByDesc('id')->firstOrFail()->type,
        );

        $stored = PaymentEvidence::query()->firstOrFail();
        $this->assertSame($ambassador->id, $stored->ambassador_user_id);
        $this->assertSame($deal->id, $stored->deal_id);
        Storage::disk((string) config('deals.payment_evidence_disk'))->assertExists($stored->path);
    }

    public function test_client_cannot_inject_party_status_or_secrets(): void
    {
        [$deal, $ambassador] = $this->openDeal();
        Sanctum::actingAs($ambassador);

        $this->post('/api/v1/deals/'.$deal->id.'/payment-evidence', [
            'kind' => PaymentEvidenceKind::Receipt->value,
            'file' => UploadedFile::fake()->image('receipt.png'),
            'business_user_id' => 9,
            'pin' => '1234',
        ], ['Accept' => 'application/json'])
            ->assertStatus(400)
            ->assertJsonPath('error.code', ApiErrorCode::VALIDATION_ERROR);

        $this->assertDatabaseCount('payment_evidence', 0);
    }

    public function test_receipt_without_file_is_rejected_and_transaction_reference_may_omit_file(): void
    {
        [$deal, $ambassador] = $this->openDeal();
        Sanctum::actingAs($ambassador);

        $this->postJson('/api/v1/deals/'.$deal->id.'/payment-evidence', [
            'kind' => PaymentEvidenceKind::Receipt->value,
        ])->assertStatus(400);

        $this->postJson('/api/v1/deals/'.$deal->id.'/payment-evidence', [
            'kind' => PaymentEvidenceKind::TransactionReference->value,
            'reference_number' => 'REF-88',
        ])->assertCreated()
            ->assertJsonPath('data.has_file', false)
            ->assertJsonPath('data.reference_number', 'REF-88');
    }

    public function test_invalid_file_type_is_rejected(): void
    {
        [$deal, $ambassador] = $this->openDeal();
        Sanctum::actingAs($ambassador);

        $this->post('/api/v1/deals/'.$deal->id.'/payment-evidence', [
            'kind' => PaymentEvidenceKind::Receipt->value,
            'file' => UploadedFile::fake()->create('payload.exe', 20, 'application/x-msdownload'),
        ], ['Accept' => 'application/json'])->assertStatus(400);

        $this->assertDatabaseCount('payment_evidence', 0);
    }

    public function test_multiple_submissions_append_and_do_not_overwrite(): void
    {
        [$deal, $ambassador] = $this->openDeal();
        Sanctum::actingAs($ambassador);

        $firstId = $this->post('/api/v1/deals/'.$deal->id.'/payment-evidence', [
            'kind' => PaymentEvidenceKind::Receipt->value,
            'file' => UploadedFile::fake()->image('first.png'),
            'note' => 'first',
        ], ['Accept' => 'application/json'])->assertCreated()->json('data.id');

        $this->post('/api/v1/deals/'.$deal->id.'/payment-evidence', [
            'kind' => PaymentEvidenceKind::TransferConfirmation->value,
            'file' => UploadedFile::fake()->image('second.png'),
            'note' => 'second',
        ], ['Accept' => 'application/json'])->assertCreated();

        $this->assertDatabaseCount('payment_evidence', 2);
        $this->assertSame('first', PaymentEvidence::query()->findOrFail($firstId)->note);
    }

    public function test_party_list_show_and_download_are_idor_safe(): void
    {
        [$dealA, $ambassadorA] = $this->openDeal();
        [$dealB, $ambassadorB] = $this->openDeal();

        Sanctum::actingAs($ambassadorA);
        $evidenceA = $this->post('/api/v1/deals/'.$dealA->id.'/payment-evidence', [
            'kind' => PaymentEvidenceKind::Receipt->value,
            'file' => UploadedFile::fake()->image('a.png'),
        ], ['Accept' => 'application/json'])->json('data.id');

        Sanctum::actingAs($ambassadorB);
        $evidenceB = $this->post('/api/v1/deals/'.$dealB->id.'/payment-evidence', [
            'kind' => PaymentEvidenceKind::Receipt->value,
            'file' => UploadedFile::fake()->image('b.png'),
        ], ['Accept' => 'application/json'])->json('data.id');

        Sanctum::actingAs($ambassadorA);
        $this->getJson('/api/v1/deals/'.$dealA->id.'/payment-evidence')
            ->assertOk()
            ->assertJsonPath('data.0.id', $evidenceA);
        $this->getJson('/api/v1/deals/'.$dealA->id.'/payment-evidence/'.$evidenceA)->assertOk();
        $this->get('/api/v1/deals/'.$dealA->id.'/payment-evidence/'.$evidenceA.'/download')->assertOk();
        $this->getJson('/api/v1/deals/'.$dealB->id.'/payment-evidence')->assertStatus(404);
        $this->getJson('/api/v1/deals/'.$dealB->id.'/payment-evidence/'.$evidenceB)->assertStatus(404);
        $this->getJson('/api/v1/deals/'.$dealA->id.'/payment-evidence/'.$evidenceB)->assertStatus(404);

        Sanctum::actingAs($dealA->business);
        $this->getJson('/api/v1/deals/'.$dealA->id.'/payment-evidence/'.$evidenceA)->assertOk();
        $this->post('/api/v1/deals/'.$dealA->id.'/payment-evidence', [
            'kind' => PaymentEvidenceKind::Receipt->value,
            'file' => UploadedFile::fake()->image('biz.png'),
        ], ['Accept' => 'application/json'])->assertStatus(403);

        Sanctum::actingAs($dealB->business);
        $this->getJson('/api/v1/deals/'.$dealA->id.'/payment-evidence/'.$evidenceA)->assertStatus(404);

        Sanctum::actingAs(User::factory()->admin()->create());
        $this->getJson('/api/v1/deals/'.$dealA->id.'/payment-evidence')->assertStatus(403);
        $this->post('/api/v1/deals/'.$dealA->id.'/payment-evidence', [
            'kind' => PaymentEvidenceKind::Receipt->value,
            'file' => UploadedFile::fake()->image('admin.png'),
        ], ['Accept' => 'application/json'])->assertStatus(403);

        $this->patchJson('/api/v1/deals/'.$dealA->id.'/payment-evidence/'.$evidenceA, ['note' => 'rewrite'])
            ->assertStatus(405);
    }

    public function test_guest_restricted_and_banned_accounts_cannot_use_evidence(): void
    {
        [$deal, $ambassador] = $this->openDeal();
        Sanctum::actingAs($ambassador);
        $evidenceId = $this->post('/api/v1/deals/'.$deal->id.'/payment-evidence', [
            'kind' => PaymentEvidenceKind::Receipt->value,
            'file' => UploadedFile::fake()->image('a.png'),
        ], ['Accept' => 'application/json'])->json('data.id');

        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/deals/'.$deal->id.'/payment-evidence')->assertStatus(401);
        $this->post('/api/v1/deals/'.$deal->id.'/payment-evidence', [
            'kind' => PaymentEvidenceKind::Receipt->value,
            'file' => UploadedFile::fake()->image('g.png'),
        ], ['Accept' => 'application/json'])->assertStatus(401);

        Sanctum::actingAs($ambassador);
        $ambassador->forceFill(['status' => AccountStatus::Restricted])->save();
        $this->getJson('/api/v1/deals/'.$deal->id.'/payment-evidence')->assertStatus(403);

        $ambassador->forceFill(['status' => AccountStatus::Suspended])->save();
        $this->getJson('/api/v1/deals/'.$deal->id.'/payment-evidence/'.$evidenceId)->assertStatus(403);

        $ambassador->forceFill(['status' => AccountStatus::Banned])->save();
        $this->getJson('/api/v1/deals/'.$deal->id.'/payment-evidence')->assertStatus(403);
    }

    public function test_failed_event_insert_rolls_back_evidence_and_file(): void
    {
        [$deal, $ambassador] = $this->openDeal();
        Sanctum::actingAs($ambassador);

        DealEvent::creating(function (DealEvent $event): void {
            if ($event->type === DealEventType::PaymentEvidenceSubmitted) {
                throw new RuntimeException('event write failed');
            }
        });

        $this->post('/api/v1/deals/'.$deal->id.'/payment-evidence', [
            'kind' => PaymentEvidenceKind::Receipt->value,
            'file' => UploadedFile::fake()->image('rollback.png'),
        ], ['Accept' => 'application/json'])->assertStatus(500);

        $this->assertDatabaseCount('payment_evidence', 0);
        $this->assertSame([], Storage::disk((string) config('deals.payment_evidence_disk'))->allFiles());
    }

    /**
     * @return array{0: Deal, 1: User}
     */
    private function openDeal(): array
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
        $dealId = $this->postJson('/api/v1/deals', ['campaign_id' => $campaign->id])
            ->assertCreated()
            ->json('data.id');

        return [Deal::query()->findOrFail($dealId), $ambassador];
    }
}
