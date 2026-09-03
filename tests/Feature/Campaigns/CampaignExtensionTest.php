<?php

namespace Tests\Feature\Campaigns;

use App\Enums\CampaignStatus;
use App\Enums\CampaignVersionStatus;
use App\Enums\PlatformPaymentStatus;
use App\Models\BusinessProfile;
use App\Models\Campaign;
use App\Models\CampaignExtension;
use App\Models\CampaignExtensionPackage;
use App\Models\CampaignVersion;
use App\Models\PlatformPayment;
use App\Models\User;
use App\Support\Api\ApiErrorCode;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CampaignExtensionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('paystack.secret_key', 'test_sk');
        Config::set('paystack.public_key', 'test_pk');
        Config::set('paystack.base_url', 'https://api.paystack.co');
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_admin_can_configure_extension_packages(): void
    {
        $admin = User::factory()->admin()->create();
        Sanctum::actingAs($admin);

        $this->postJson('/api/v1/admin/campaign-extension-packages', [
            'name' => '30 additional days',
            'duration_days' => 30,
            'amount_minor' => 1500000,
            'currency' => 'NGN',
        ])
            ->assertCreated()
            ->assertJsonPath('data.duration_days', 30)
            ->assertJsonPath('data.amount_minor', 1500000);

        $id = CampaignExtensionPackage::query()->value('id');

        $this->patchJson('/api/v1/admin/campaign-extension-packages/'.$id, [
            'is_active' => false,
        ])->assertOk()->assertJsonPath('data.is_active', false);
    }

    public function test_initialize_uses_server_side_package_price_not_client_amount(): void
    {
        $this->fakePaystack();
        [$owner, $campaign, $package] = $this->activeCampaignWithPackage();
        Sanctum::actingAs($owner);

        $this->postJson('/api/v1/campaigns/'.$campaign->id.'/extensions/initialize', [
            'package_id' => $package->id,
            'amount_minor' => 1,
        ])
            ->assertCreated()
            ->assertJsonPath('data.payment.amount_minor', $package->amount_minor)
            ->assertJsonPath('data.payment.status', PlatformPaymentStatus::Pending->value)
            ->assertJsonPath('data.authorization_url', 'https://checkout.paystack.com/test');

        $this->getJson('/api/v1/campaigns/'.$campaign->id.'/extension-packages')
            ->assertOk()
            ->assertJsonPath('data.0.duration_days', 30);

        $this->assertSame($package->amount_minor, PlatformPayment::query()->value('amount_minor'));
        $this->assertSame($campaign->listing_expires_at?->toIso8601String(), $campaign->fresh()->listing_expires_at?->toIso8601String());
        $this->assertSame(0, CampaignExtension::query()->count());
    }

    public function test_successful_payment_extends_active_campaign_from_existing_expiry(): void
    {
        Carbon::setTestNow('2026-09-02 12:00:00');
        $this->fakePaystack();
        [$owner, $campaign, $package] = $this->activeCampaignWithPackage();
        $originalExpiry = $campaign->listing_expires_at;
        Sanctum::actingAs($owner);

        $reference = $this->initializeReference($campaign, $package);

        $this->postJson('/api/v1/campaigns/'.$campaign->id.'/extensions/verify', [
            'reference' => $reference,
        ])
            ->assertOk()
            ->assertJsonPath('data.duration_days', 30)
            ->assertJsonPath('data.previous_status', CampaignStatus::Active->value)
            ->assertJsonPath('data.resulting_status', CampaignStatus::Active->value);

        $campaign->refresh();
        $this->assertSame(CampaignStatus::Active, $campaign->status);
        $this->assertSame(
            $originalExpiry->copy()->addDays(30)->format('Y-m-d H:i:s'),
            $campaign->listing_expires_at?->format('Y-m-d H:i:s'),
        );
        $this->assertSame(CampaignVersionStatus::Published, $campaign->currentVersion?->status);
        $this->assertNotNull(Campaign::query()->find($campaign->id));
    }

    public function test_expired_campaign_becomes_active_from_now_after_paid_extension(): void
    {
        Carbon::setTestNow('2026-09-02 12:00:00');
        $this->fakePaystack();
        [$owner, $campaign, $package] = $this->activeCampaignWithPackage();
        $campaign->forceFill([
            'status' => CampaignStatus::Expired,
            'listing_expires_at' => now()->subDay(),
            'expired_at' => now()->subDay(),
        ])->save();
        Sanctum::actingAs($owner);

        $reference = $this->initializeReference($campaign, $package);
        $this->postJson('/api/v1/campaigns/'.$campaign->id.'/extensions/verify', [
            'reference' => $reference,
        ])->assertOk();

        $campaign->refresh();
        $this->assertSame(CampaignStatus::Active, $campaign->status);
        $this->assertSame('2026-10-02 12:00:00', $campaign->listing_expires_at?->format('Y-m-d H:i:s'));
        $this->assertNotNull($campaign->expired_at);
    }

    public function test_ineligible_lifecycle_states_cannot_initialize_extension(): void
    {
        $this->fakePaystack();
        $package = CampaignExtensionPackage::factory()->create();
        [$owner, $draft] = $this->businessCampaign();
        $this->publishVersion($owner, $draft);

        Sanctum::actingAs($owner);
        $this->postJson('/api/v1/campaigns/'.$draft->id.'/extensions/initialize', [
            'package_id' => $package->id,
        ])->assertStatus(409);

        [$owner, $submitted] = $this->submittedCampaign();
        Sanctum::actingAs($owner);
        $this->postJson('/api/v1/campaigns/'.$submitted->id.'/extensions/initialize', [
            'package_id' => $package->id,
        ])->assertStatus(409);

        [$owner, $active] = $this->activeCampaign();
        Sanctum::actingAs($owner);
        $this->postJson('/api/v1/campaigns/'.$active->id.'/deactivate')->assertOk();
        $this->postJson('/api/v1/campaigns/'.$active->id.'/extensions/initialize', [
            'package_id' => $package->id,
        ])->assertStatus(409);

        [$owner, $toSuspend] = $this->activeCampaign();
        $admin = User::factory()->admin()->create();
        Sanctum::actingAs($admin);
        $this->postJson('/api/v1/admin/campaigns/'.$toSuspend->id.'/suspend', [
            'reason' => 'Risk review.',
        ])->assertOk();
        Sanctum::actingAs($owner);
        $this->postJson('/api/v1/campaigns/'.$toSuspend->id.'/extensions/initialize', [
            'package_id' => $package->id,
        ])->assertStatus(409);

        [$owner, $toClose] = $this->activeCampaign();
        Sanctum::actingAs($admin);
        $this->postJson('/api/v1/admin/campaigns/'.$toClose->id.'/close', [
            'reason' => 'Closed.',
        ])->assertOk();
        Sanctum::actingAs($owner);
        $this->postJson('/api/v1/campaigns/'.$toClose->id.'/extensions/initialize', [
            'package_id' => $package->id,
        ])->assertStatus(409);
    }

    public function test_failed_and_mismatched_payments_do_not_extend(): void
    {
        $this->fakePaystack(verifyStatus: 'failed');
        [$owner, $campaign, $package] = $this->activeCampaignWithPackage();
        $expiry = $campaign->listing_expires_at?->format('Y-m-d H:i:s');
        Sanctum::actingAs($owner);
        $reference = $this->initializeReference($campaign, $package);

        $this->postJson('/api/v1/campaigns/'.$campaign->id.'/extensions/verify', [
            'reference' => $reference,
        ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', ApiErrorCode::BUSINESS_VALIDATION);

        $this->assertSame($expiry, $campaign->fresh()->listing_expires_at?->format('Y-m-d H:i:s'));
        $this->assertSame(0, CampaignExtension::query()->count());
        $this->assertSame(PlatformPaymentStatus::Failed, PlatformPayment::query()->first()->status);

        Http::fake($this->paystackHandler(verifyAmount: 1));
        $reference2 = $this->initializeReference($campaign, $package);
        $this->postJson('/api/v1/campaigns/'.$campaign->id.'/extensions/verify', [
            'reference' => $reference2,
        ])->assertStatus(422);

        $this->assertSame($expiry, $campaign->fresh()->listing_expires_at?->format('Y-m-d H:i:s'));
        $this->assertSame(0, CampaignExtension::query()->count());
    }

    public function test_webhook_and_verify_are_idempotent(): void
    {
        $this->fakePaystack();
        [$owner, $campaign, $package] = $this->activeCampaignWithPackage();
        Sanctum::actingAs($owner);
        $reference = $this->initializeReference($campaign, $package);
        $body = json_encode([
            'event' => 'charge.success',
            'data' => ['reference' => $reference],
        ], JSON_THROW_ON_ERROR);

        $this->call('POST', '/api/v1/webhooks/paystack', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_PAYSTACK_SIGNATURE' => hash_hmac('sha512', $body, (string) config('paystack.secret_key')),
        ], content: $body)->assertOk();

        $expiry = $campaign->fresh()->listing_expires_at?->format('Y-m-d H:i:s');
        $this->assertSame(1, CampaignExtension::query()->count());

        $this->call('POST', '/api/v1/webhooks/paystack', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_PAYSTACK_SIGNATURE' => hash_hmac('sha512', $body, (string) config('paystack.secret_key')),
        ], content: $body)->assertOk();

        $this->postJson('/api/v1/campaigns/'.$campaign->id.'/extensions/verify', [
            'reference' => $reference,
        ])->assertOk();

        $this->assertSame(1, CampaignExtension::query()->count());
        $this->assertSame($expiry, $campaign->fresh()->listing_expires_at?->format('Y-m-d H:i:s'));
    }

    public function test_invalid_webhook_signature_is_rejected(): void
    {
        $this->fakePaystack();
        [$owner, $campaign, $package] = $this->activeCampaignWithPackage();
        Sanctum::actingAs($owner);
        $reference = $this->initializeReference($campaign, $package);

        $this->postJson('/api/v1/webhooks/paystack', [
            'event' => 'charge.success',
            'data' => ['reference' => $reference],
        ])->assertStatus(403);

        $this->assertSame(0, CampaignExtension::query()->count());
        $this->assertSame(CampaignStatus::Active, $campaign->fresh()->status);
    }

    public function test_wrong_currency_does_not_extend(): void
    {
        $this->fakePaystack(verifyCurrency: 'USD');
        [$owner, $campaign, $package] = $this->activeCampaignWithPackage();
        Sanctum::actingAs($owner);
        $reference = $this->initializeReference($campaign, $package);

        $this->postJson('/api/v1/campaigns/'.$campaign->id.'/extensions/verify', [
            'reference' => $reference,
        ])->assertStatus(422);

        $this->assertSame(0, CampaignExtension::query()->count());
    }

    public function test_authorization_idor_and_account_status_rules(): void
    {
        $this->fakePaystack();
        [$owner, $campaign, $package] = $this->activeCampaignWithPackage();
        $intruder = User::factory()->business()->create();
        BusinessProfile::factory()->for($intruder)->create();
        Sanctum::actingAs($intruder);

        $this->postJson('/api/v1/campaigns/'.$campaign->id.'/extensions/initialize', [
            'package_id' => $package->id,
        ])->assertStatus(403);

        Sanctum::actingAs(User::factory()->ambassador()->create());
        $this->postJson('/api/v1/campaigns/'.$campaign->id.'/extensions/initialize', [
            'package_id' => $package->id,
        ])->assertStatus(403);

        Sanctum::actingAs(User::factory()->business()->restricted()->create());
        $this->postJson('/api/v1/campaigns/'.$campaign->id.'/extensions/initialize', [
            'package_id' => $package->id,
        ])->assertStatus(403);

        Sanctum::actingAs(User::factory()->business()->suspended()->create());
        $this->postJson('/api/v1/campaigns/'.$campaign->id.'/extensions/initialize', [
            'package_id' => $package->id,
        ])->assertStatus(403);

        Sanctum::actingAs(User::factory()->business()->banned()->create());
        $this->postJson('/api/v1/campaigns/'.$campaign->id.'/extensions/initialize', [
            'package_id' => $package->id,
        ])->assertStatus(403);

        Sanctum::actingAs($owner);
        $this->postJson('/api/v1/admin/campaign-extension-packages', [
            'name' => 'Should fail',
            'duration_days' => 30,
            'amount_minor' => 100,
        ])->assertStatus(403);
    }

    public function test_activation_without_published_version_cannot_apply_extension(): void
    {
        $this->fakePaystack();
        [$owner, $campaign] = $this->businessCampaign();
        $package = CampaignExtensionPackage::factory()->create();
        $campaign->forceFill([
            'status' => CampaignStatus::Expired,
            'listing_expires_at' => now()->subDay(),
        ])->save();
        CampaignVersion::factory()->for($campaign)->create([
            'status' => CampaignVersionStatus::Draft,
            'version_number' => 1,
        ]);

        Sanctum::actingAs($owner);
        $reference = $this->initializeReference($campaign, $package);
        $this->postJson('/api/v1/campaigns/'.$campaign->id.'/extensions/verify', [
            'reference' => $reference,
        ])->assertStatus(422);

        $this->assertSame(CampaignStatus::Expired, $campaign->fresh()->status);
        $this->assertSame(0, CampaignExtension::query()->count());
    }

    /**
     * @return array{0: User, 1: Campaign, 2: CampaignExtensionPackage}
     */
    private function activeCampaignWithPackage(): array
    {
        [$owner, $campaign] = $this->activeCampaign();
        $package = CampaignExtensionPackage::factory()->create();

        return [$owner, $campaign, $package];
    }

    /**
     * @return array{0: User, 1: Campaign}
     */
    private function businessCampaign(): array
    {
        $owner = User::factory()->business()->create();
        BusinessProfile::factory()->for($owner)->create();
        $campaign = Campaign::factory()->for($owner)->create();

        return [$owner, $campaign];
    }

    /**
     * @return array{0: User, 1: Campaign}
     */
    private function submittedCampaign(): array
    {
        [$owner, $campaign] = $this->businessCampaign();
        $this->publishVersion($owner, $campaign);
        Sanctum::actingAs($owner);
        $this->postJson('/api/v1/campaigns/'.$campaign->id.'/submit')->assertOk();

        return [$owner, $campaign->fresh()];
    }

    /**
     * @return array{0: User, 1: Campaign}
     */
    private function activeCampaign(): array
    {
        [$owner, $campaign] = $this->submittedCampaign();
        $admin = User::factory()->admin()->create();
        Sanctum::actingAs($admin);
        $this->postJson('/api/v1/admin/campaigns/'.$campaign->id.'/approve')->assertOk();
        $this->postJson('/api/v1/admin/campaigns/'.$campaign->id.'/activate')->assertOk();

        return [$owner, $campaign->fresh()];
    }

    private function publishVersion(User $owner, Campaign $campaign): void
    {
        Sanctum::actingAs($owner);
        $this->postJson('/api/v1/campaigns/'.$campaign->id.'/versions', [
            'product_name' => 'Classroom internet package',
            'pricing_method' => 'fixed',
            'price_amount' => 50000,
            'commission_type' => 'percentage',
            'commission_rate' => 10,
            'commission_trigger' => 'payment_confirmation',
            'commission_payment_deadline_days' => 7,
            'refund_cancellation_rules' => 'Published refund rules.',
            'payment_destination_name' => 'Ada Ventures Ltd',
            'payment_provider' => 'Example Bank',
            'payment_account_identifier' => '0000000000',
            'payment_instructions' => 'Pay the business directly.',
        ])->assertCreated();

        $this->postJson('/api/v1/campaigns/'.$campaign->id.'/versions/1/publish')->assertOk();
    }

    private function initializeReference(Campaign $campaign, CampaignExtensionPackage $package): string
    {
        $response = $this->postJson('/api/v1/campaigns/'.$campaign->id.'/extensions/initialize', [
            'package_id' => $package->id,
        ])->assertCreated();

        return (string) $response->json('data.payment.reference');
    }

    private function fakePaystack(
        string $verifyStatus = 'success',
        int $verifyAmount = 500000,
        string $verifyCurrency = 'NGN',
    ): void {
        Http::fake($this->paystackHandler($verifyStatus, $verifyAmount, $verifyCurrency));
    }

    /**
     * @return \Closure(Request): PromiseInterface
     */
    private function paystackHandler(
        string $verifyStatus = 'success',
        int $verifyAmount = 500000,
        string $verifyCurrency = 'NGN',
    ): \Closure {
        $providerId = 1000;

        return function (Request $request) use ($verifyStatus, $verifyAmount, $verifyCurrency, &$providerId) {
            if (str_contains($request->url(), '/transaction/initialize')) {
                $reference = (string) $request['reference'];

                return Http::response([
                    'status' => true,
                    'data' => [
                        'authorization_url' => 'https://checkout.paystack.com/test',
                        'access_code' => 'access',
                        'reference' => $reference,
                    ],
                ]);
            }

            if (str_contains($request->url(), '/transaction/verify/')) {
                $providerId++;
                $reference = (string) str($request->url())->after('/transaction/verify/');

                return Http::response([
                    'status' => true,
                    'data' => [
                        'status' => $verifyStatus,
                        'amount' => $verifyAmount,
                        'currency' => $verifyCurrency,
                        'reference' => $reference,
                        'id' => $providerId,
                    ],
                ]);
            }

            return Http::response(['message' => 'unexpected Paystack URL'], 500);
        };
    }
}
