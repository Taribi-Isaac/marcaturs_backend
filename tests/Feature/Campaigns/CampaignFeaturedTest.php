<?php

namespace Tests\Feature\Campaigns;

use App\Enums\CampaignStatus;
use App\Enums\NotificationType;
use App\Enums\PlatformPaymentPurpose;
use App\Enums\PlatformPaymentStatus;
use App\Models\BusinessProfile;
use App\Models\Campaign;
use App\Models\CampaignExtension;
use App\Models\CampaignExtensionPackage;
use App\Models\CampaignFeaturedPackage;
use App\Models\CampaignFeaturedPurchase;
use App\Models\Category;
use App\Models\PlatformPayment;
use App\Models\User;
use App\Support\Api\ApiErrorCode;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CampaignFeaturedTest extends TestCase
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

    public function test_admin_can_configure_featured_packages_and_business_reads_active_only(): void
    {
        $admin = User::factory()->admin()->create();
        Sanctum::actingAs($admin);

        $this->postJson('/api/v1/admin/campaign-featured-packages', [
            'name' => '7-day Featured',
            'duration_days' => 7,
            'amount_minor' => 250000,
            'currency' => 'NGN',
        ])
            ->assertCreated()
            ->assertJsonPath('data.duration_days', 7)
            ->assertJsonPath('data.amount_minor', 250000);

        $id = (int) CampaignFeaturedPackage::query()->value('id');

        $this->patchJson('/api/v1/admin/campaign-featured-packages/'.$id, [
            'is_active' => false,
        ])->assertOk()->assertJsonPath('data.is_active', false);

        CampaignFeaturedPackage::factory()->create([
            'name' => '14-day Featured',
            'duration_days' => 14,
            'amount_minor' => 400000,
            'is_active' => true,
        ]);

        $business = User::factory()->business()->create();
        Sanctum::actingAs($business);

        $this->getJson('/api/v1/campaign-featured/packages')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.duration_days', 14);

        $this->postJson('/api/v1/admin/campaign-featured-packages', [
            'name' => 'Nope',
            'duration_days' => 3,
            'amount_minor' => 100,
        ])->assertStatus(403);
    }

    public function test_initialize_uses_server_side_package_price_not_client_amount(): void
    {
        $this->fakePaystack();
        [$owner, $campaign, $package] = $this->activeCampaignWithPackage();
        Sanctum::actingAs($owner);

        $this->postJson('/api/v1/campaigns/'.$campaign->id.'/featured/initialize', [
            'package_id' => $package->id,
            'amount_minor' => 1,
            'duration_days' => 999,
            'is_featured' => true,
        ])
            ->assertCreated()
            ->assertJsonPath('data.payment.amount_minor', $package->amount_minor)
            ->assertJsonPath('data.payment.duration_days', $package->duration_days)
            ->assertJsonPath('data.payment.purpose', PlatformPaymentPurpose::CampaignFeatured->value)
            ->assertJsonPath('data.payment.status', PlatformPaymentStatus::Pending->value);

        $this->assertFalse($campaign->fresh()->is_featured);
        $this->assertSame(0, CampaignFeaturedPurchase::query()->count());
    }

    public function test_successful_payment_activates_featured_and_notifies_business(): void
    {
        Carbon::setTestNow('2026-09-07 12:00:00');
        $this->fakePaystack();
        [$owner, $campaign, $package] = $this->activeCampaignWithPackage();
        $listingExpiry = $campaign->listing_expires_at?->format('Y-m-d H:i:s');
        Sanctum::actingAs($owner);

        $reference = $this->initializeReference($campaign, $package);

        $this->postJson('/api/v1/campaigns/'.$campaign->id.'/featured/verify', [
            'reference' => $reference,
        ])
            ->assertOk()
            ->assertJsonPath('data.duration_days', 7)
            ->assertJsonPath('data.package_name', $package->name)
            ->assertJsonPath('data.is_active', true);

        $campaign->refresh();
        $this->assertTrue($campaign->is_featured);
        $this->assertSame($listingExpiry, $campaign->listing_expires_at?->format('Y-m-d H:i:s'));
        $this->assertSame(
            '2026-09-14 12:00:00',
            CampaignFeaturedPurchase::query()->first()?->expires_at?->format('Y-m-d H:i:s'),
        );

        $this->assertDatabaseHas('notifications', [
            'notifiable_id' => $owner->id,
        ]);

        $notification = DatabaseNotification::query()->where('notifiable_id', $owner->id)->first();
        $this->assertNotNull($notification);
        $this->assertSame(
            NotificationType::CampaignFeaturedPurchased->value,
            $notification->data['notification_type'] ?? null,
        );
        $this->assertArrayNotHasKey('password', $notification->data);
        $this->assertArrayNotHasKey('authorization_url', $notification->data);
    }

    public function test_stacking_extends_existing_featured_expiry(): void
    {
        Carbon::setTestNow('2026-09-07 12:00:00');
        $this->fakePaystack();
        [$owner, $campaign, $package] = $this->activeCampaignWithPackage();
        Sanctum::actingAs($owner);

        $first = $this->initializeReference($campaign, $package);
        $this->postJson('/api/v1/campaigns/'.$campaign->id.'/featured/verify', [
            'reference' => $first,
        ])->assertOk();

        $this->assertSame(
            '2026-09-14 12:00:00',
            $campaign->fresh()->featuredPurchases()->orderByDesc('id')->first()?->expires_at?->format('Y-m-d H:i:s'),
        );

        $second = $this->initializeReference($campaign, $package);
        $this->postJson('/api/v1/campaigns/'.$campaign->id.'/featured/verify', [
            'reference' => $second,
        ])->assertOk();

        $this->assertSame(2, CampaignFeaturedPurchase::query()->count());
        $this->assertSame(
            '2026-09-21 12:00:00',
            CampaignFeaturedPurchase::query()->orderByDesc('id')->first()?->expires_at?->format('Y-m-d H:i:s'),
        );
        $this->assertTrue($campaign->fresh()->is_featured);
    }

    public function test_duplicate_webhook_and_verify_are_idempotent(): void
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

        $expires = CampaignFeaturedPurchase::query()->first()?->expires_at?->format('Y-m-d H:i:s');
        $this->assertSame(1, CampaignFeaturedPurchase::query()->count());

        $this->call('POST', '/api/v1/webhooks/paystack', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_PAYSTACK_SIGNATURE' => hash_hmac('sha512', $body, (string) config('paystack.secret_key')),
        ], content: $body)->assertOk();

        $this->postJson('/api/v1/campaigns/'.$campaign->id.'/featured/verify', [
            'reference' => $reference,
        ])->assertOk();

        $this->assertSame(1, CampaignFeaturedPurchase::query()->count());
        $this->assertSame(
            $expires,
            CampaignFeaturedPurchase::query()->first()?->expires_at?->format('Y-m-d H:i:s'),
        );
    }

    public function test_failed_mismatched_and_invalid_signature_do_not_activate(): void
    {
        $this->fakePaystack(verifyStatus: 'failed');
        [$owner, $campaign, $package] = $this->activeCampaignWithPackage();
        Sanctum::actingAs($owner);
        $reference = $this->initializeReference($campaign, $package);

        $this->postJson('/api/v1/campaigns/'.$campaign->id.'/featured/verify', [
            'reference' => $reference,
        ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', ApiErrorCode::BUSINESS_VALIDATION);

        $this->assertFalse($campaign->fresh()->is_featured);
        $this->assertSame(0, CampaignFeaturedPurchase::query()->count());
        $this->assertSame(PlatformPaymentStatus::Failed, PlatformPayment::query()->first()->status);

        Http::fake($this->paystackHandler(verifyAmount: 1));
        $reference2 = $this->initializeReference($campaign, $package);
        $this->postJson('/api/v1/campaigns/'.$campaign->id.'/featured/verify', [
            'reference' => $reference2,
        ])->assertStatus(422);

        $this->fakePaystack(verifyCurrency: 'USD');
        $reference3 = $this->initializeReference($campaign, $package);
        $this->postJson('/api/v1/campaigns/'.$campaign->id.'/featured/verify', [
            'reference' => $reference3,
        ])->assertStatus(422);

        $this->fakePaystack();
        $reference4 = $this->initializeReference($campaign, $package);
        $this->postJson('/api/v1/webhooks/paystack', [
            'event' => 'charge.success',
            'data' => ['reference' => $reference4],
        ])->assertStatus(403);

        $this->assertSame(0, CampaignFeaturedPurchase::query()->count());
    }

    public function test_ineligible_lifecycle_states_cannot_initialize_featured(): void
    {
        $this->fakePaystack();
        $package = CampaignFeaturedPackage::factory()->create();

        foreach ([
            CampaignStatus::Draft,
            CampaignStatus::Submitted,
            CampaignStatus::Approved,
            CampaignStatus::Expired,
            CampaignStatus::Deactivated,
            CampaignStatus::Suspended,
            CampaignStatus::Closed,
        ] as $status) {
            [$owner, $campaign] = $this->activeCampaign();
            $campaign->forceFill(['status' => $status])->save();
            Sanctum::actingAs($owner);
            $this->postJson('/api/v1/campaigns/'.$campaign->id.'/featured/initialize', [
                'package_id' => $package->id,
            ])->assertStatus(409);
        }

        [$owner, $expiring] = $this->activeCampaign();
        $expiring->forceFill(['status' => CampaignStatus::Expiring])->save();
        Sanctum::actingAs($owner);
        $this->postJson('/api/v1/campaigns/'.$expiring->id.'/featured/initialize', [
            'package_id' => $package->id,
        ])->assertCreated();
    }

    public function test_missing_published_version_and_unassignable_category_rejected(): void
    {
        $this->fakePaystack();
        $package = CampaignFeaturedPackage::factory()->create();
        [$owner, $campaign] = $this->businessCampaign();
        $campaign->forceFill([
            'status' => CampaignStatus::Active,
            'listing_starts_at' => now(),
            'listing_expires_at' => now()->addDays(10),
            'current_campaign_version_id' => null,
        ])->save();

        Sanctum::actingAs($owner);
        $this->postJson('/api/v1/campaigns/'.$campaign->id.'/featured/initialize', [
            'package_id' => $package->id,
        ])->assertStatus(422);

        [$owner, $active] = $this->activeCampaign();
        $category = Category::factory()->prohibited()->create();
        $active->forceFill(['category_id' => $category->id])->save();
        Sanctum::actingAs($owner);
        $this->postJson('/api/v1/campaigns/'.$active->id.'/featured/initialize', [
            'package_id' => $package->id,
        ])->assertStatus(422);
    }

    public function test_ownership_and_role_rules(): void
    {
        $this->fakePaystack();
        [$owner, $campaign, $package] = $this->activeCampaignWithPackage();
        $intruder = User::factory()->business()->create();
        BusinessProfile::factory()->for($intruder)->create();
        Sanctum::actingAs($intruder);

        $this->postJson('/api/v1/campaigns/'.$campaign->id.'/featured/initialize', [
            'package_id' => $package->id,
        ])->assertStatus(403);

        Sanctum::actingAs(User::factory()->ambassador()->create());
        $this->postJson('/api/v1/campaigns/'.$campaign->id.'/featured/initialize', [
            'package_id' => $package->id,
        ])->assertStatus(403);

        Sanctum::actingAs(User::factory()->admin()->create());
        $this->postJson('/api/v1/campaigns/'.$campaign->id.'/featured/initialize', [
            'package_id' => $package->id,
        ])->assertStatus(403);
    }

    public function test_marketplace_featured_first_filter_and_hide_when_undiscoverable(): void
    {
        Carbon::setTestNow('2026-09-07 12:00:00');
        $this->fakePaystack();
        [$ownerA, $featuredCampaign, $package] = $this->activeCampaignWithPackage();
        [$ownerB, $plainCampaign] = $this->activeCampaign();

        $plainCampaign->forceFill([
            'listing_starts_at' => now()->addHour(),
        ])->save();
        $featuredCampaign->forceFill([
            'listing_starts_at' => now()->subHour(),
        ])->save();

        Sanctum::actingAs($ownerA);
        $reference = $this->initializeReference($featuredCampaign, $package);
        $this->postJson('/api/v1/campaigns/'.$featuredCampaign->id.'/featured/verify', [
            'reference' => $reference,
        ])->assertOk();

        $this->getJson('/api/v1/marketplace/campaigns')
            ->assertOk()
            ->assertJsonPath('data.0.id', $featuredCampaign->id)
            ->assertJsonPath('data.0.is_featured', true)
            ->assertJsonPath('data.1.id', $plainCampaign->id)
            ->assertJsonPath('data.1.is_featured', false);

        $this->getJson('/api/v1/marketplace/campaigns?featured=true')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $featuredCampaign->id);

        $admin = User::factory()->admin()->create();
        Sanctum::actingAs($admin);
        $this->postJson('/api/v1/admin/campaigns/'.$featuredCampaign->id.'/suspend', [
            'reason' => 'Risk review.',
        ])->assertOk();

        $this->getJson('/api/v1/marketplace/campaigns/'.$featuredCampaign->id)
            ->assertStatus(404);

        $this->assertTrue($featuredCampaign->fresh()->is_featured);
        $this->assertSame(
            $featuredCampaign->listing_expires_at?->format('Y-m-d H:i:s'),
            $featuredCampaign->fresh()->listing_expires_at?->format('Y-m-d H:i:s'),
        );
        unset($ownerB);
    }

    public function test_expired_featured_flag_is_cleared_by_lifecycle_command(): void
    {
        Carbon::setTestNow('2026-09-07 12:00:00');
        $this->fakePaystack();
        [$owner, $campaign, $package] = $this->activeCampaignWithPackage();
        Sanctum::actingAs($owner);
        $reference = $this->initializeReference($campaign, $package);
        $this->postJson('/api/v1/campaigns/'.$campaign->id.'/featured/verify', [
            'reference' => $reference,
        ])->assertOk();

        Carbon::setTestNow('2026-09-15 12:00:00');
        $this->artisan('campaigns:process-lifecycle')->assertSuccessful();

        $this->assertFalse($campaign->fresh()->is_featured);
        $this->getJson('/api/v1/marketplace/campaigns?featured=true')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_featured_does_not_create_extension_or_refund_surface(): void
    {
        $this->fakePaystack();
        [$owner, $campaign, $package] = $this->activeCampaignWithPackage();
        Sanctum::actingAs($owner);
        $reference = $this->initializeReference($campaign, $package);
        $this->postJson('/api/v1/campaigns/'.$campaign->id.'/featured/verify', [
            'reference' => $reference,
        ])->assertOk();

        $this->assertSame(0, CampaignExtension::query()->count());
        $this->assertSame(0, CampaignExtensionPackage::query()->count());
        $this->assertDatabaseMissing('migrations', ['migration' => 'create_refunds_table']);

        $this->postJson('/api/v1/campaigns/'.$campaign->id.'/featured/refund')->assertStatus(404);
        $this->postJson('/api/v1/refunds')->assertStatus(404);
    }

    public function test_package_snapshot_preserved_after_package_change(): void
    {
        $this->fakePaystack();
        [$owner, $campaign, $package] = $this->activeCampaignWithPackage();
        Sanctum::actingAs($owner);
        $reference = $this->initializeReference($campaign, $package);
        $this->postJson('/api/v1/campaigns/'.$campaign->id.'/featured/verify', [
            'reference' => $reference,
        ])->assertOk();

        $admin = User::factory()->admin()->create();
        Sanctum::actingAs($admin);
        $this->patchJson('/api/v1/admin/campaign-featured-packages/'.$package->id, [
            'name' => 'Renamed package',
            'amount_minor' => 999999,
        ])->assertOk();

        Sanctum::actingAs($owner);
        $this->getJson('/api/v1/campaigns/'.$campaign->id.'/featured')
            ->assertOk()
            ->assertJsonPath('data.is_featured', true)
            ->assertJsonPath('data.purchases.0.package_name', '7-day Featured')
            ->assertJsonPath('data.purchases.0.amount_minor', 250000);

        Sanctum::actingAs($admin);
        $this->getJson('/api/v1/admin/campaigns/'.$campaign->id.'/featured')
            ->assertOk()
            ->assertJsonPath('data.0.package_name', '7-day Featured');
    }

    public function test_payment_becoming_ineligible_before_confirm_does_not_activate(): void
    {
        $this->fakePaystack();
        [$owner, $campaign, $package] = $this->activeCampaignWithPackage();
        Sanctum::actingAs($owner);
        $reference = $this->initializeReference($campaign, $package);

        $campaign->forceFill(['status' => CampaignStatus::Suspended])->save();

        $this->postJson('/api/v1/campaigns/'.$campaign->id.'/featured/verify', [
            'reference' => $reference,
        ])->assertStatus(409);

        $this->assertSame(0, CampaignFeaturedPurchase::query()->count());
        $this->assertFalse($campaign->fresh()->is_featured);
        $this->assertSame(PlatformPaymentStatus::Pending, PlatformPayment::query()->first()->status);
    }

    /**
     * @return array{0: User, 1: Campaign, 2: CampaignFeaturedPackage}
     */
    private function activeCampaignWithPackage(): array
    {
        [$owner, $campaign] = $this->activeCampaign();
        $package = CampaignFeaturedPackage::factory()->create();

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

    private function initializeReference(Campaign $campaign, CampaignFeaturedPackage $package): string
    {
        $response = $this->postJson('/api/v1/campaigns/'.$campaign->id.'/featured/initialize', [
            'package_id' => $package->id,
        ])->assertCreated();

        return (string) $response->json('data.payment.reference');
    }

    private function fakePaystack(
        string $verifyStatus = 'success',
        int $verifyAmount = 250000,
        string $verifyCurrency = 'NGN',
    ): void {
        Http::fake($this->paystackHandler($verifyStatus, $verifyAmount, $verifyCurrency));
    }

    /**
     * @return \Closure(Request): PromiseInterface
     */
    private function paystackHandler(
        string $verifyStatus = 'success',
        int $verifyAmount = 250000,
        string $verifyCurrency = 'NGN',
    ): \Closure {
        $providerId = 2000;

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
