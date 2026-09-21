<?php

namespace Tests\Feature\Certification;

use App\Enums\AdminStaffRole;
use App\Enums\CertificationEnrollmentStatus;
use App\Enums\CertificationProgrammeStatus;
use App\Enums\CertificationProgrammeVersionStatus;
use App\Enums\NotificationType;
use App\Enums\PlatformPaymentPurpose;
use App\Enums\PlatformPaymentStatus;
use App\Models\CertificationEnrollment;
use App\Models\CertificationProgramme;
use App\Models\CertificationProgrammeVersion;
use App\Models\PlatformPayment;
use App\Models\User;
use App\Support\Api\ApiErrorCode;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CertificationEnrollmentBillingTest extends TestCase
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

    public function test_ambassador_purchase_uses_server_fee_and_activates_enrollment(): void
    {
        $this->fakePaystack(verifyAmount: 1500000);
        [$ambassador, $programme, $version] = $this->publishedProgramme(fee: 1500000);
        Sanctum::actingAs($ambassador);

        $init = $this->postJson("/api/v1/certification/programmes/{$programme->id}/purchase/initialize", [
            'amount_minor' => 1,
        ])
            ->assertCreated()
            ->assertJsonPath('data.payment.purpose', PlatformPaymentPurpose::CertificationEnrollment->value)
            ->assertJsonPath('data.payment.amount_minor', 1500000)
            ->assertJsonPath('data.payment.status', PlatformPaymentStatus::Pending->value);

        $reference = (string) $init->json('data.payment.reference');
        $payment = PlatformPayment::query()->where('reference', $reference)->firstOrFail();
        $this->assertSame($version->id, $payment->certification_programme_version_id);
        $this->assertNull($payment->campaign_id);

        $this->postJson('/api/v1/certification/purchases/verify', [
            'reference' => $reference,
        ])
            ->assertOk()
            ->assertJsonPath('data.status', CertificationEnrollmentStatus::Active->value)
            ->assertJsonPath('data.programme_version_id', $version->id)
            ->assertJsonPath('data.fee_amount_minor', 1500000);

        $this->assertSame(1, CertificationEnrollment::query()->count());
        $this->assertSame(PlatformPaymentStatus::Paid, $payment->fresh()->status);

        $this->assertTrue(
            DatabaseNotification::query()
                ->where('notifiable_id', $ambassador->id)
                ->where('data->notification_type', NotificationType::CertificationEnrollmentActivated->value)
                ->exists(),
        );

        $this->getJson('/api/v1/certification/enrollments')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_version_snapshot_survives_later_publish_and_fee_change_on_new_version(): void
    {
        $this->fakePaystack(verifyAmount: 1500000);
        [$ambassador, $programme, $v1] = $this->publishedProgramme(fee: 1500000);
        Sanctum::actingAs($ambassador);

        $reference = (string) $this->postJson("/api/v1/certification/programmes/{$programme->id}/purchase/initialize")
            ->assertCreated()
            ->json('data.payment.reference');

        $admin = User::factory()->adminStaff(AdminStaffRole::Operations)->create();
        Sanctum::actingAs($admin);
        $this->postJson("/api/v1/admin/certification/programmes/{$programme->id}/versions", [
            'fee_amount_minor' => 2500000,
            'pass_mark_percent' => 80,
        ])->assertCreated();
        $this->postJson("/api/v1/admin/certification/programmes/{$programme->id}/versions/2/publish")->assertOk();

        Sanctum::actingAs($ambassador);
        $this->postJson('/api/v1/certification/purchases/verify', [
            'reference' => $reference,
        ])
            ->assertOk()
            ->assertJsonPath('data.programme_version_id', $v1->id)
            ->assertJsonPath('data.fee_amount_minor', 1500000);

        $this->assertSame(2, $programme->fresh()->currentPublishedVersion?->version_number);
        $this->assertSame($v1->id, CertificationEnrollment::query()->value('programme_version_id'));
    }

    public function test_duplicate_enrollment_and_idempotent_verify_webhook(): void
    {
        $this->fakePaystack(verifyAmount: 1500000);
        [$ambassador, $programme] = $this->publishedProgramme(fee: 1500000);
        Sanctum::actingAs($ambassador);

        $reference = (string) $this->postJson("/api/v1/certification/programmes/{$programme->id}/purchase/initialize")
            ->json('data.payment.reference');

        $this->postJson('/api/v1/certification/purchases/verify', ['reference' => $reference])->assertOk();
        $this->postJson('/api/v1/certification/purchases/verify', ['reference' => $reference])
            ->assertOk()
            ->assertJsonPath('data.id', CertificationEnrollment::query()->value('id'));

        $body = json_encode([
            'event' => 'charge.success',
            'data' => ['reference' => $reference],
        ], JSON_THROW_ON_ERROR);

        $this->call('POST', '/api/v1/webhooks/paystack', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_PAYSTACK_SIGNATURE' => hash_hmac('sha512', $body, (string) config('paystack.secret_key')),
        ], content: $body)->assertOk();

        $this->assertSame(1, CertificationEnrollment::query()->count());

        $this->postJson("/api/v1/certification/programmes/{$programme->id}/purchase/initialize")
            ->assertStatus(409)
            ->assertJsonPath('error.code', ApiErrorCode::CONFLICT);
    }

    public function test_failed_and_mismatched_payments_do_not_enroll(): void
    {
        $this->fakePaystack(verifyStatus: 'failed', verifyAmount: 1500000);
        [$ambassador, $programme] = $this->publishedProgramme(fee: 1500000);
        Sanctum::actingAs($ambassador);
        $reference = (string) $this->postJson("/api/v1/certification/programmes/{$programme->id}/purchase/initialize")
            ->json('data.payment.reference');

        $this->postJson('/api/v1/certification/purchases/verify', ['reference' => $reference])
            ->assertStatus(422);
        $this->assertSame(0, CertificationEnrollment::query()->count());
        $this->assertSame(PlatformPaymentStatus::Failed, PlatformPayment::query()->first()->status);

        Http::fake($this->paystackHandler(verifyAmount: 1));
        $reference2 = (string) $this->postJson("/api/v1/certification/programmes/{$programme->id}/purchase/initialize")
            ->json('data.payment.reference');
        $this->postJson('/api/v1/certification/purchases/verify', ['reference' => $reference2])
            ->assertStatus(422);
        $this->assertSame(0, CertificationEnrollment::query()->count());
    }

    public function test_draft_programme_cannot_be_purchased_and_wrong_roles_are_rejected(): void
    {
        $this->fakePaystack();
        $draft = CertificationProgramme::factory()->create([
            'status' => CertificationProgrammeStatus::Draft,
        ]);
        CertificationProgrammeVersion::factory()->for($draft, 'programme')->publishable()->create();

        Sanctum::actingAs(User::factory()->ambassador()->create());
        $this->postJson("/api/v1/certification/programmes/{$draft->id}/purchase/initialize")
            ->assertStatus(409);

        Sanctum::actingAs(User::factory()->business()->create());
        [$ambassador, $programme] = $this->publishedProgramme();
        $this->postJson("/api/v1/certification/programmes/{$programme->id}/purchase/initialize")
            ->assertStatus(403);

        unset($ambassador);
    }

    public function test_missing_paystack_configuration_returns_participant_safe_message(): void
    {
        Config::set('paystack.secret_key', '');
        [, $programme] = $this->publishedProgramme();
        Sanctum::actingAs(User::factory()->ambassador()->create());

        $this->postJson("/api/v1/certification/programmes/{$programme->id}/purchase/initialize")
            ->assertStatus(503)
            ->assertJsonPath('error.code', ApiErrorCode::SERVICE_UNAVAILABLE)
            ->assertJsonPath('error.message', 'Payments are temporarily unavailable. Please try again later.')
            ->assertJsonMissing(['Platform payments are not configured.']);
    }

    public function test_enrollment_idor_and_admin_learners_view(): void
    {
        $this->fakePaystack(verifyAmount: 1500000);
        [$a, $programme] = $this->publishedProgramme(fee: 1500000);
        Sanctum::actingAs($a);
        $reference = (string) $this->postJson("/api/v1/certification/programmes/{$programme->id}/purchase/initialize")
            ->json('data.payment.reference');
        $enrollmentId = (int) $this->postJson('/api/v1/certification/purchases/verify', ['reference' => $reference])
            ->json('data.id');

        Sanctum::actingAs(User::factory()->ambassador()->create());
        $this->getJson("/api/v1/certification/enrollments/{$enrollmentId}")
            ->assertStatus(404);

        Sanctum::actingAs(User::factory()->adminStaff(AdminStaffRole::Verification)->create());
        $this->getJson('/api/v1/admin/certification/enrollments')
            ->assertStatus(403);

        Sanctum::actingAs(User::factory()->adminStaff(AdminStaffRole::Operations)->create());
        $this->getJson('/api/v1/admin/certification/enrollments')
            ->assertOk()
            ->assertJsonPath('data.0.id', $enrollmentId)
            ->assertJsonPath('data.0.user.email', $a->email);
    }

    public function test_paid_payment_without_enrollment_is_recoverable_via_verify(): void
    {
        $this->fakePaystack(verifyAmount: 1500000);
        [$ambassador, $programme, $version] = $this->publishedProgramme(fee: 1500000);
        Sanctum::actingAs($ambassador);
        $reference = (string) $this->postJson("/api/v1/certification/programmes/{$programme->id}/purchase/initialize")
            ->json('data.payment.reference');

        $payment = PlatformPayment::query()->where('reference', $reference)->firstOrFail();
        $payment->status = PlatformPaymentStatus::Paid;
        $payment->paid_at = now();
        $payment->provider_reference = 'manual-recovery';
        $payment->save();

        $this->assertSame(0, CertificationEnrollment::query()->count());

        $this->postJson('/api/v1/certification/purchases/verify', ['reference' => $reference])
            ->assertOk()
            ->assertJsonPath('data.programme_version_id', $version->id);

        $this->assertSame(1, CertificationEnrollment::query()->count());
    }

    public function test_provider_success_with_existing_enrollment_reconciles_payment_without_duplicate(): void
    {
        $this->fakePaystack(verifyAmount: 1500000);
        [$ambassador, $programme, $version] = $this->publishedProgramme(fee: 1500000);
        Sanctum::actingAs($ambassador);

        $firstReference = (string) $this->postJson("/api/v1/certification/programmes/{$programme->id}/purchase/initialize")
            ->assertCreated()
            ->json('data.payment.reference');

        $enrollmentId = (int) $this->postJson('/api/v1/certification/purchases/verify', ['reference' => $firstReference])
            ->assertOk()
            ->json('data.id');

        $secondPayment = new PlatformPayment;
        $secondPayment->forceFill([
            'user_id' => $ambassador->id,
            'campaign_id' => null,
            'certification_programme_id' => $programme->id,
            'certification_programme_version_id' => $version->id,
            'purpose' => PlatformPaymentPurpose::CertificationEnrollment,
            'provider' => 'paystack',
            'reference' => 'mh_cert_second_confirmed',
            'amount_minor' => 1500000,
            'currency' => 'NGN',
            'status' => PlatformPaymentStatus::Pending,
        ])->save();

        $beforeNotifications = DatabaseNotification::query()
            ->where('notifiable_id', $ambassador->id)
            ->where('data->notification_type', NotificationType::CertificationEnrollmentActivated->value)
            ->count();

        $this->postJson('/api/v1/certification/purchases/verify', ['reference' => $secondPayment->reference])
            ->assertOk()
            ->assertJsonPath('data.id', $enrollmentId);

        $this->assertSame(1, CertificationEnrollment::query()->count());
        $this->assertSame(PlatformPaymentStatus::Paid, $secondPayment->fresh()->status);
        $this->assertNotNull($secondPayment->fresh()->paid_at);
        $this->assertSame(
            $enrollmentId,
            (int) CertificationEnrollment::query()->value('id'),
        );

        $afterNotifications = DatabaseNotification::query()
            ->where('notifiable_id', $ambassador->id)
            ->where('data->notification_type', NotificationType::CertificationEnrollmentActivated->value)
            ->count();
        $this->assertSame($beforeNotifications, $afterNotifications);

        $body = json_encode([
            'event' => 'charge.success',
            'data' => ['reference' => $secondPayment->reference],
        ], JSON_THROW_ON_ERROR);

        $this->call('POST', '/api/v1/webhooks/paystack', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_PAYSTACK_SIGNATURE' => hash_hmac('sha512', $body, (string) config('paystack.secret_key')),
        ], content: $body)->assertOk();

        $this->assertSame(1, CertificationEnrollment::query()->count());
        $this->assertSame($beforeNotifications, DatabaseNotification::query()
            ->where('notifiable_id', $ambassador->id)
            ->where('data->notification_type', NotificationType::CertificationEnrollmentActivated->value)
            ->count());
    }

    public function test_extension_payment_reference_cannot_activate_certification_enrollment(): void
    {
        $this->fakePaystack(verifyAmount: 500000);
        [$ambassador] = $this->publishedProgramme(fee: 1500000);
        Sanctum::actingAs($ambassador);

        $extensionPayment = new PlatformPayment;
        $extensionPayment->forceFill([
            'user_id' => $ambassador->id,
            'campaign_id' => null,
            'purpose' => PlatformPaymentPurpose::CampaignExtension,
            'provider' => 'paystack',
            'reference' => 'mh_ext_cross_purpose_cert',
            'amount_minor' => 500000,
            'currency' => 'NGN',
            'duration_days' => 30,
            'status' => PlatformPaymentStatus::Pending,
        ])->save();

        $this->postJson('/api/v1/certification/purchases/verify', ['reference' => $extensionPayment->reference])
            ->assertStatus(404)
            ->assertJsonPath('error.code', ApiErrorCode::NOT_FOUND);

        $this->assertSame(0, CertificationEnrollment::query()->count());
        $this->assertSame(PlatformPaymentStatus::Pending, $extensionPayment->fresh()->status);
    }

    /**
     * @return array{0: User, 1: CertificationProgramme, 2: CertificationProgrammeVersion}
     */
    private function publishedProgramme(int $fee = 1500000): array
    {
        $ambassador = User::factory()->ambassador()->create();
        $programme = CertificationProgramme::factory()->create([
            'status' => CertificationProgrammeStatus::Published,
            'name' => 'Ambassador Professional Certification',
        ]);
        $version = CertificationProgrammeVersion::factory()->for($programme, 'programme')->create([
            'version_number' => 1,
            'status' => CertificationProgrammeVersionStatus::Published,
            'fee_amount_minor' => $fee,
            'fee_currency' => 'NGN',
            'pass_mark_percent' => '75.00',
            'published_at' => now(),
        ]);
        $programme->current_published_version_id = $version->id;
        $programme->save();

        return [$ambassador, $programme, $version];
    }

    private function fakePaystack(
        string $verifyStatus = 'success',
        int $verifyAmount = 1500000,
        string $verifyCurrency = 'NGN',
    ): void {
        Http::fake($this->paystackHandler($verifyStatus, $verifyAmount, $verifyCurrency));
    }

    /**
     * @return \Closure(Request): PromiseInterface
     */
    private function paystackHandler(
        string $verifyStatus = 'success',
        int $verifyAmount = 1500000,
        string $verifyCurrency = 'NGN',
    ): \Closure {
        $providerId = 9000;

        return function (Request $request) use ($verifyStatus, $verifyAmount, $verifyCurrency, &$providerId) {
            if (str_contains($request->url(), '/transaction/initialize')) {
                return Http::response([
                    'status' => true,
                    'data' => [
                        'authorization_url' => 'https://checkout.paystack.com/cert-test',
                        'access_code' => 'access',
                        'reference' => (string) $request['reference'],
                    ],
                ]);
            }

            if (str_contains($request->url(), '/transaction/verify/')) {
                $providerId++;

                return Http::response([
                    'status' => true,
                    'data' => [
                        'status' => $verifyStatus,
                        'amount' => $verifyAmount,
                        'currency' => $verifyCurrency,
                        'reference' => (string) str($request->url())->after('/transaction/verify/'),
                        'id' => $providerId,
                    ],
                ]);
            }

            return Http::response(['message' => 'unexpected Paystack URL'], 500);
        };
    }
}
