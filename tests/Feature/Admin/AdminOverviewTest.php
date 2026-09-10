<?php

namespace Tests\Feature\Admin;

use App\Enums\AccountStatus;
use App\Enums\CampaignStatus;
use App\Enums\CommissionStatus;
use App\Enums\DealStatus;
use App\Enums\DisputeStatus;
use App\Enums\PlatformPaymentPurpose;
use App\Enums\PlatformPaymentStatus;
use App\Enums\VerificationSubmissionStatus;
use App\Models\Campaign;
use App\Models\CampaignVersion;
use App\Models\Commission;
use App\Models\Conversation;
use App\Models\Deal;
use App\Models\Dispute;
use App\Models\DisputeCategory;
use App\Models\PlatformPayment;
use App\Models\User;
use App\Models\VerificationRequirement;
use App\Models\VerificationSubmission;
use App\Support\Api\ApiErrorCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminOverviewTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_guest_business_and_ambassador_cannot_access_overview(): void
    {
        $this->getJson('/api/v1/admin/overview')
            ->assertStatus(401)
            ->assertJsonPath('error.code', ApiErrorCode::UNAUTHENTICATED);

        Sanctum::actingAs(User::factory()->business()->create());
        $this->getJson('/api/v1/admin/overview')->assertStatus(403);

        Sanctum::actingAs(User::factory()->ambassador()->create());
        $this->getJson('/api/v1/admin/overview')->assertStatus(403);
    }

    public function test_admin_overview_returns_deterministic_domain_sections(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-10 15:00:00', 'UTC'));

        $admin = User::factory()->admin()->create();
        $businessActive = User::factory()->business()->create(['status' => AccountStatus::Active]);
        $businessRestricted = User::factory()->business()->create(['status' => AccountStatus::Restricted]);
        $ambassadorActive = User::factory()->ambassador()->create(['status' => AccountStatus::Active]);
        User::factory()->ambassador()->create(['status' => AccountStatus::Suspended]);
        User::factory()->business()->create(['status' => AccountStatus::Banned]);

        $requirement = VerificationRequirement::factory()->create();
        VerificationSubmission::factory()
            ->for($businessActive)
            ->for($requirement, 'requirement')
            ->approved()
            ->create();
        VerificationSubmission::factory()
            ->for($businessRestricted)
            ->for($requirement, 'requirement')
            ->create(['status' => VerificationSubmissionStatus::Pending]);

        Campaign::factory()->for($businessActive)->create(['status' => CampaignStatus::Submitted]);
        $featured = Campaign::factory()->for($businessActive)->create([
            'status' => CampaignStatus::Active,
            'is_featured' => true,
        ]);
        Campaign::factory()->for($businessActive)->create(['status' => CampaignStatus::Expired]);

        $pendingDeal = $this->makeDeal($businessActive, $ambassadorActive, DealStatus::PaymentPending);
        $sealedDeal = $this->makeDeal($businessActive, $ambassadorActive, DealStatus::Sealed);
        $completedDeal = $this->makeDeal($businessActive, $ambassadorActive, DealStatus::Completed);
        $this->makeDeal($businessActive, $ambassadorActive, DealStatus::Cancelled);

        $this->attachCommission($sealedDeal, CommissionStatus::Due, now()->subDay());
        $this->attachCommission($completedDeal, CommissionStatus::Due, now()->addDays(3));
        $paidDeal = $this->makeDeal($businessActive, $ambassadorActive, DealStatus::Sealed);
        $this->attachCommission($paidDeal, CommissionStatus::Paid, now()->subDays(2));
        $receivedDeal = $this->makeDeal($businessActive, $ambassadorActive, DealStatus::Completed);
        $this->attachCommission($receivedDeal, CommissionStatus::Received, now()->subDays(10));

        $this->makeDispute($pendingDeal, DisputeStatus::Submitted);
        $this->makeDispute($sealedDeal, DisputeStatus::Resolved);

        Conversation::factory()->create([
            'business_user_id' => $businessActive->id,
            'ambassador_user_id' => $ambassadorActive->id,
            'reported_at' => now(),
            'reported_by' => $businessActive->id,
            'report_reason' => 'Spam',
        ]);

        $this->makePlatformPayment(
            $businessActive,
            $featured,
            PlatformPaymentPurpose::CampaignExtension,
            PlatformPaymentStatus::Paid,
            10_000,
            'NGN',
            now(),
        );
        $this->makePlatformPayment(
            $businessActive,
            $featured,
            PlatformPaymentPurpose::CampaignFeatured,
            PlatformPaymentStatus::Paid,
            25_000,
            'NGN',
            now(),
        );
        $this->makePlatformPayment(
            $businessActive,
            $featured,
            PlatformPaymentPurpose::CampaignExtension,
            PlatformPaymentStatus::Pending,
            99_000,
            'NGN',
            null,
        );
        $this->makePlatformPayment(
            $businessActive,
            $featured,
            PlatformPaymentPurpose::CampaignFeatured,
            PlatformPaymentStatus::Failed,
            50_000,
            'NGN',
            null,
        );
        $this->makePlatformPayment(
            $businessActive,
            $featured,
            PlatformPaymentPurpose::CampaignExtension,
            PlatformPaymentStatus::Cancelled,
            40_000,
            'NGN',
            null,
        );
        $this->makePlatformPayment(
            $businessActive,
            $featured,
            PlatformPaymentPurpose::CampaignExtension,
            PlatformPaymentStatus::Paid,
            5_000,
            'NGN',
            now()->subMonths(2),
        );

        Sanctum::actingAs($admin);
        $response = $this->getJson('/api/v1/admin/overview')->assertOk();

        $data = $response->json('data');
        $this->assertSame('UTC', $data['timezone']);
        $this->assertArrayHasKey('generated_at', $data);

        $this->assertSame(3, $data['users']['business_registered']);
        $this->assertSame(2, $data['users']['ambassador_registered']);
        $this->assertSame(1, $data['users']['business_active']);
        $this->assertSame(1, $data['users']['ambassador_active']);
        $this->assertSame(1, $data['users']['restricted']);
        $this->assertSame(1, $data['users']['suspended']);
        $this->assertSame(1, $data['users']['banned']);
        $this->assertSame(1, $data['users']['business_verified']);
        $this->assertSame(0, $data['users']['ambassador_verified']);
        $this->assertArrayNotHasKey('admin_registered', $data['users']);

        $this->assertSame(1, $data['campaigns']['by_status']['submitted']);
        $this->assertGreaterThanOrEqual(1, $data['campaigns']['by_status']['active']);
        $this->assertSame(1, $data['campaigns']['by_status']['expired']);
        $this->assertSame(1, $data['campaigns']['featured_flagged']);
        $this->assertSame(1, $data['campaigns']['awaiting_admin_review']);

        $this->assertSame(6, $data['deals']['total']);
        $this->assertSame(1, $data['deals']['payment_pending']);
        $this->assertSame(2, $data['deals']['sealed']);
        $this->assertSame(2, $data['deals']['completed']);
        $this->assertSame(1, $data['deals']['cancelled']);
        $this->assertSame(4, $data['deals']['payment_confirmed']);

        $this->assertSame(2, $data['commissions']['due']);
        $this->assertSame(1, $data['commissions']['overdue']);
        $this->assertSame(1, $data['commissions']['paid']);
        $this->assertSame(1, $data['commissions']['received']);
        $this->assertSame(
            'Business_to_Ambassador_obligation_not_platform_revenue',
            $data['commissions']['boundary'],
        );

        $this->assertSame(1, $data['disputes']['open']);
        $this->assertSame(1, $data['disputes']['by_status']['submitted']);
        $this->assertSame(1, $data['disputes']['by_status']['resolved']);

        $this->assertSame(1, $data['verification']['submissions_awaiting_review']);
        $this->assertSame(1, $data['verification']['submissions_approved']);

        $this->assertSame('successful_platform_payment_volume', $data['platform_payments']['terminology']);
        $allTime = $data['platform_payments']['all_time']['by_currency'][0];
        $this->assertSame('NGN', $allTime['currency']);
        $this->assertSame(3, $allTime['successful_payment_count']);
        $this->assertSame(40_000, $allTime['successful_amount_minor']);
        $this->assertSame(2, $allTime['by_purpose']['campaign_extension']['successful_payment_count']);
        $this->assertSame(15_000, $allTime['by_purpose']['campaign_extension']['successful_amount_minor']);
        $this->assertSame(1, $allTime['by_purpose']['campaign_featured']['successful_payment_count']);
        $this->assertSame(25_000, $allTime['by_purpose']['campaign_featured']['successful_amount_minor']);

        $today = $data['platform_payments']['today']['by_currency'][0];
        $this->assertSame(2, $today['successful_payment_count']);
        $this->assertSame(35_000, $today['successful_amount_minor']);

        $month = $data['platform_payments']['this_month']['by_currency'][0];
        $this->assertSame(2, $month['successful_payment_count']);
        $this->assertSame(35_000, $month['successful_amount_minor']);

        $this->assertSame(1, $data['attention']['campaigns_awaiting_review']);
        $this->assertSame(1, $data['attention']['verification_submissions_awaiting_review']);
        $this->assertSame(1, $data['attention']['open_disputes']);
        $this->assertSame(1, $data['attention']['commissions_overdue']);
        $this->assertSame(1, $data['attention']['deals_payment_pending']);
        $this->assertSame(1, $data['attention']['reported_conversations']);

        $encoded = json_encode($data);
        $this->assertIsString($encoded);
        $this->assertStringNotContainsString('password', $encoded);
        $this->assertStringNotContainsString('payment_account_identifier', $encoded);
        $this->assertStringNotContainsString('authorization_url', $encoded);
        $this->assertStringNotContainsString('storage_path', $encoded);
        $this->assertStringNotContainsString('secret', strtolower($encoded));
    }

    public function test_overdue_requires_due_status_and_past_due_at(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-10 12:00:00', 'UTC'));
        $admin = User::factory()->admin()->create();
        $business = User::factory()->business()->create();
        $ambassador = User::factory()->ambassador()->create();

        $overdueDeal = $this->makeDeal($business, $ambassador, DealStatus::Sealed);
        $this->attachCommission($overdueDeal, CommissionStatus::Due, now()->subHour());

        $notYetDue = $this->makeDeal($business, $ambassador, DealStatus::Sealed);
        $this->attachCommission($notYetDue, CommissionStatus::Due, now()->addHour());

        $paidPastDue = $this->makeDeal($business, $ambassador, DealStatus::Sealed);
        $this->attachCommission($paidPastDue, CommissionStatus::Paid, now()->subDays(5));

        Sanctum::actingAs($admin);
        $this->getJson('/api/v1/admin/overview')
            ->assertOk()
            ->assertJsonPath('data.commissions.due', 2)
            ->assertJsonPath('data.commissions.overdue', 1)
            ->assertJsonPath('data.commissions.paid', 1)
            ->assertJsonPath('data.attention.commissions_overdue', 1);
    }

    public function test_open_disputes_independent_of_completed_deal(): void
    {
        $admin = User::factory()->admin()->create();
        $business = User::factory()->business()->create();
        $ambassador = User::factory()->ambassador()->create();
        $completed = $this->makeDeal($business, $ambassador, DealStatus::Completed);
        $this->makeDispute($completed, DisputeStatus::EvidenceRequested);

        Sanctum::actingAs($admin);
        $this->getJson('/api/v1/admin/overview')
            ->assertOk()
            ->assertJsonPath('data.deals.completed', 1)
            ->assertJsonPath('data.disputes.open', 1)
            ->assertJsonPath('data.attention.open_disputes', 1);
    }

    private function makeDeal(User $business, User $ambassador, DealStatus $status): Deal
    {
        $campaign = Campaign::factory()->for($business)->create(['status' => CampaignStatus::Active]);
        $version = CampaignVersion::factory()->for($campaign)->published()->create();
        $campaign->forceFill(['current_campaign_version_id' => $version->id])->save();

        return Deal::factory()->create([
            'business_user_id' => $business->id,
            'ambassador_user_id' => $ambassador->id,
            'campaign_id' => $campaign->id,
            'campaign_version_id' => $version->id,
            'status' => $status,
            'product_name' => 'Overview product',
            'commission_type' => 'percentage',
            'commission_rate' => '10.00',
            'commission_amount' => '10000.00',
            'commission_trigger' => 'payment_confirmation',
            'commission_payment_deadline_days' => 7,
            'confirmed_payment_amount' => $status === DealStatus::PaymentPending ? null : '100000.00',
            'confirmed_at' => $status === DealStatus::PaymentPending ? null : now()->subDay(),
        ]);
    }

    private function attachCommission(Deal $deal, CommissionStatus $status, Carbon $dueAt): Commission
    {
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

    private function makeDispute(Deal $deal, DisputeStatus $status): Dispute
    {
        $categoryId = DisputeCategory::query()->where('code', 'other')->value('id');
        $dispute = new Dispute;
        $dispute->forceFill([
            'reference' => 'MH-D-OV-'.uniqid(),
            'deal_id' => $deal->id,
            'commission_id' => Commission::query()->where('deal_id', $deal->id)->value('id'),
            'category_id' => $categoryId,
            'reporter_user_id' => $deal->ambassador_user_id,
            'accused_user_id' => $deal->business_user_id,
            'description' => 'Overview dispute fixture.',
            'status' => $status,
            'resolved_at' => $status === DisputeStatus::Resolved || $status === DisputeStatus::Closed ? now() : null,
            'closed_at' => $status === DisputeStatus::Closed ? now() : null,
        ])->save();

        return $dispute;
    }

    private function makePlatformPayment(
        User $business,
        Campaign $campaign,
        PlatformPaymentPurpose $purpose,
        PlatformPaymentStatus $status,
        int $amountMinor,
        string $currency,
        ?Carbon $paidAt,
    ): PlatformPayment {
        $payment = new PlatformPayment;
        $payment->forceFill([
            'user_id' => $business->id,
            'campaign_id' => $campaign->id,
            'purpose' => $purpose,
            'provider' => 'paystack',
            'reference' => 'ov-'.uniqid(),
            'provider_reference' => $status === PlatformPaymentStatus::Paid ? 'prv-'.uniqid() : null,
            'amount_minor' => $amountMinor,
            'currency' => $currency,
            'duration_days' => 7,
            'status' => $status,
            'paid_at' => $paidAt,
            'failed_at' => in_array($status, [PlatformPaymentStatus::Failed, PlatformPaymentStatus::Cancelled], true)
                ? now()
                : null,
        ])->save();

        return $payment;
    }
}
