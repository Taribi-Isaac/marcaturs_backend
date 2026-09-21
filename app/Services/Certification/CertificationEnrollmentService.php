<?php

namespace App\Services\Certification;

use App\Contracts\Payments\PlatformPaymentGateway;
use App\Enums\AdminPermission;
use App\Enums\CertificationAdminEventAction;
use App\Enums\CertificationEnrollmentStatus;
use App\Enums\CertificationProgrammeStatus;
use App\Enums\CertificationProgrammeVersionStatus;
use App\Enums\PlatformPaymentPurpose;
use App\Enums\PlatformPaymentStatus;
use App\Models\CertificationAdminEvent;
use App\Models\CertificationEnrollment;
use App\Models\CertificationProgramme;
use App\Models\CertificationProgrammeVersion;
use App\Models\PlatformPayment;
use App\Models\User;
use App\Services\Admin\AdminAuthorization;
use App\Services\Notifications\CertificationEnrollmentNotificationDispatcher;
use App\Support\Api\ApiErrorCode;
use App\Support\Api\ApiResponse;
use App\Support\Payments\ParticipantPaymentMessages;
use App\Support\Payments\PaystackReturnUrl;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class CertificationEnrollmentService
{
    public function __construct(
        private readonly PlatformPaymentGateway $gateway,
        private readonly AdminAuthorization $authorization,
        private readonly CertificationEnrollmentNotificationDispatcher $notifications,
    ) {}

    /**
     * @return Collection<int, CertificationEnrollment>
     */
    public function indexForAmbassador(User $user): Collection
    {
        $this->assertAmbassador($user);

        return CertificationEnrollment::query()
            ->where('user_id', $user->id)
            ->with(['programme', 'programmeVersion', 'payment'])
            ->orderByDesc('id')
            ->get();
    }

    public function showForAmbassador(User $user, CertificationEnrollment $enrollment): CertificationEnrollment
    {
        $this->assertAmbassador($user);
        $this->assertEnrollmentOwner($user, $enrollment);

        return $enrollment->load(['programme', 'programmeVersion', 'payment']);
    }

    /**
     * @return Collection<int, CertificationEnrollment>
     */
    public function adminIndex(User $admin, ?int $programmeId = null): Collection
    {
        $this->authorization->assert($admin, AdminPermission::CertificationLearnersView);

        $query = CertificationEnrollment::query()
            ->with(['user', 'programme', 'programmeVersion', 'payment'])
            ->orderByDesc('id');

        if ($programmeId !== null) {
            $query->where('programme_id', $programmeId);
        }

        return $query->limit(200)->get();
    }

    public function adminShow(User $admin, CertificationEnrollment $enrollment): CertificationEnrollment
    {
        $this->authorization->assert($admin, AdminPermission::CertificationLearnersView);

        return $enrollment->load(['user', 'programme', 'programmeVersion', 'payment']);
    }

    /**
     * @return array{payment: PlatformPayment, authorization_url: string, access_code: ?string}
     */
    public function initialize(User $user, CertificationProgramme $programme): array
    {
        $this->assertAmbassador($user);

        $version = $this->resolvePurchasableVersion($programme);
        $this->assertNotAlreadyEnrolled($user, $programme);

        if ((int) ($version->fee_amount_minor ?? 0) < 1 || blank($version->fee_currency)) {
            throw new HttpResponseException(ApiResponse::error(
                ApiErrorCode::BUSINESS_VALIDATION,
                'This certification programme is not priced for purchase.',
                422,
            ));
        }

        $secret = (string) config('paystack.secret_key');

        if ($secret === '') {
            Log::warning('Certification purchase initialize blocked: platform payment provider is not configured');

            throw new HttpResponseException(ApiResponse::error(
                ApiErrorCode::SERVICE_UNAVAILABLE,
                ParticipantPaymentMessages::TEMPORARILY_UNAVAILABLE,
                503,
            ));
        }

        $reference = 'mh_cert_'.strtolower((string) Str::ulid());
        $callback = PaystackReturnUrl::certificationPurchase();

        $initialization = $this->gateway->initialize(
            $user->email,
            (int) $version->fee_amount_minor,
            (string) $version->fee_currency,
            $reference,
            $callback,
            [
                'purpose' => PlatformPaymentPurpose::CertificationEnrollment->value,
                'programme_id' => $programme->id,
                'programme_version_id' => $version->id,
            ],
        );

        $payment = new PlatformPayment;
        $payment->user_id = $user->id;
        $payment->campaign_id = null;
        $payment->certification_programme_id = $programme->id;
        $payment->certification_programme_version_id = $version->id;
        $payment->purpose = PlatformPaymentPurpose::CertificationEnrollment;
        $payment->provider = 'paystack';
        $payment->reference = $initialization->reference;
        $payment->amount_minor = (int) $version->fee_amount_minor;
        $payment->currency = (string) $version->fee_currency;
        $payment->status = PlatformPaymentStatus::Pending;
        $payment->authorization_url = $initialization->authorizationUrl;
        $payment->save();

        $this->recordEvent(
            $user,
            $programme,
            $version,
            CertificationAdminEventAction::PurchaseInitialized,
            [
                'platform_payment_id' => $payment->id,
                'reference' => $payment->reference,
                'amount_minor' => $payment->amount_minor,
                'currency' => $payment->currency,
            ],
        );

        return [
            'payment' => $payment->fresh(),
            'authorization_url' => $initialization->authorizationUrl,
            'access_code' => $initialization->accessCode,
        ];
    }

    public function confirmOwnedReference(User $user, string $reference): CertificationEnrollment
    {
        $this->assertAmbassador($user);

        $payment = PlatformPayment::query()->where('reference', $reference)->first();

        if (
            $payment === null
            || $payment->user_id !== $user->id
            || $payment->purpose !== PlatformPaymentPurpose::CertificationEnrollment
        ) {
            throw new HttpResponseException(ApiResponse::error(
                ApiErrorCode::NOT_FOUND,
                'Payment reference was not found for this certification purchase.',
                404,
            ));
        }

        return $this->confirmReference($reference);
    }

    public function confirmReference(string $reference): CertificationEnrollment
    {
        $verification = $this->gateway->verify($reference);

        try {
            $outcome = DB::transaction(function () use ($reference, $verification): array {
                $payment = PlatformPayment::query()
                    ->where('reference', $reference)
                    ->lockForUpdate()
                    ->first();

                if ($payment === null) {
                    return ['error' => 'not_found'];
                }

                if ($payment->purpose !== PlatformPaymentPurpose::CertificationEnrollment) {
                    return ['error' => 'wrong_purpose'];
                }

                $existing = CertificationEnrollment::query()
                    ->where('platform_payment_id', $payment->id)
                    ->first();

                if ($existing !== null) {
                    return ['enrollment' => $existing->load(['programme', 'programmeVersion', 'payment']), 'created' => false];
                }

                if (! $verification->isSuccessful()) {
                    $this->markUnsuccessful($payment, $verification->status);

                    return ['error' => 'not_successful'];
                }

                if (
                    $verification->amountMinor !== $payment->amount_minor
                    || strtoupper($verification->currency) !== strtoupper($payment->currency)
                ) {
                    Log::warning('Certification platform payment amount or currency mismatch', [
                        'reference' => $payment->reference,
                        'expected_amount_minor' => $payment->amount_minor,
                        'expected_currency' => $payment->currency,
                    ]);

                    $this->markUnsuccessful($payment, 'mismatch');

                    return ['error' => 'mismatch'];
                }

                if (
                    $payment->certification_programme_id === null
                    || $payment->certification_programme_version_id === null
                ) {
                    Log::error('Certification payment missing programme context', [
                        'reference' => $payment->reference,
                    ]);

                    return ['error' => 'missing_context'];
                }

                $existingEnrollment = CertificationEnrollment::query()
                    ->where('user_id', $payment->user_id)
                    ->where('programme_id', $payment->certification_programme_id)
                    ->lockForUpdate()
                    ->first();

                if ($existingEnrollment !== null) {
                    // Provider confirmed success after enrollment already exists (retry, race,
                    // or a second initialized reference). Reconcile payment; do not duplicate.
                    $this->markPaid($payment, $verification->providerReference);

                    return [
                        'enrollment' => $existingEnrollment->load(['programme', 'programmeVersion', 'payment']),
                        'created' => false,
                    ];
                }

                $version = CertificationProgrammeVersion::query()
                    ->whereKey($payment->certification_programme_version_id)
                    ->lockForUpdate()
                    ->first();

                if (
                    $version === null
                    || (int) $version->programme_id !== (int) $payment->certification_programme_id
                ) {
                    return ['error' => 'missing_context'];
                }

                $this->markPaid($payment, $verification->providerReference);

                $enrollment = new CertificationEnrollment;
                $enrollment->user_id = $payment->user_id;
                $enrollment->programme_id = $payment->certification_programme_id;
                $enrollment->programme_version_id = $payment->certification_programme_version_id;
                $enrollment->platform_payment_id = $payment->id;
                $enrollment->status = CertificationEnrollmentStatus::Active;
                $enrollment->fee_amount_minor = $payment->amount_minor;
                $enrollment->fee_currency = $payment->currency;
                $enrollment->enrolled_at = now();
                $enrollment->save();

                $programme = CertificationProgramme::query()->findOrFail($enrollment->programme_id);

                $this->recordEvent(
                    User::query()->findOrFail($payment->user_id),
                    $programme,
                    $version,
                    CertificationAdminEventAction::EnrollmentActivated,
                    [
                        'enrollment_id' => $enrollment->id,
                        'platform_payment_id' => $payment->id,
                        'reference' => $payment->reference,
                        'programme_version_id' => $version->id,
                        'fee_amount_minor' => $enrollment->fee_amount_minor,
                        'fee_currency' => $enrollment->fee_currency,
                    ],
                );

                return [
                    'enrollment' => $enrollment->fresh()->load(['programme', 'programmeVersion', 'payment']),
                    'created' => true,
                ];
            });
        } catch (UniqueConstraintViolationException) {
            // Transaction rolled back: reconcile provider-confirmed payment against the winner.
            return $this->reconcileAfterEnrollmentConflict($reference, $verification->providerReference);
        }

        if (($outcome['error'] ?? null) === 'not_found' || ($outcome['error'] ?? null) === 'wrong_purpose') {
            throw new HttpResponseException(ApiResponse::error(
                ApiErrorCode::NOT_FOUND,
                'Payment reference was not found.',
                404,
            ));
        }

        if (($outcome['error'] ?? null) === 'not_successful') {
            throw new HttpResponseException(ApiResponse::error(
                ApiErrorCode::BUSINESS_VALIDATION,
                'Payment was not successful.',
                422,
            ));
        }

        if (($outcome['error'] ?? null) === 'mismatch') {
            throw new HttpResponseException(ApiResponse::error(
                ApiErrorCode::BUSINESS_VALIDATION,
                'Payment details did not match the initialized certification purchase.',
                422,
            ));
        }

        if (($outcome['error'] ?? null) === 'missing_context') {
            throw new HttpResponseException(ApiResponse::error(
                ApiErrorCode::SERVER_ERROR,
                'Certification payment context is incomplete.',
                500,
            ));
        }

        /** @var CertificationEnrollment $enrollment */
        $enrollment = $outcome['enrollment'];

        if (($outcome['created'] ?? false) === true) {
            $this->notifications->notifyActivated($enrollment);
        }

        return $enrollment;
    }

    public function handleWebhookEvent(string $event, ?string $reference): void
    {
        if ($reference === null || $reference === '') {
            return;
        }

        if ($event === 'charge.success') {
            $this->confirmReference($reference);

            return;
        }

        if (in_array($event, ['charge.failed', 'charge.abandoned'], true)) {
            DB::transaction(function () use ($reference, $event): void {
                $payment = PlatformPayment::query()
                    ->where('reference', $reference)
                    ->lockForUpdate()
                    ->first();

                if (
                    $payment === null
                    || $payment->purpose !== PlatformPaymentPurpose::CertificationEnrollment
                    || $payment->status === PlatformPaymentStatus::Paid
                ) {
                    return;
                }

                $this->markUnsuccessful($payment, $event);
            });
        }
    }

    private function resolvePurchasableVersion(CertificationProgramme $programme): CertificationProgrammeVersion
    {
        if ($programme->status !== CertificationProgrammeStatus::Published) {
            throw new HttpResponseException(ApiResponse::error(
                ApiErrorCode::CONFLICT,
                'This certification programme is not available for purchase.',
                409,
            ));
        }

        if ($programme->current_published_version_id === null) {
            throw new HttpResponseException(ApiResponse::error(
                ApiErrorCode::BUSINESS_VALIDATION,
                'This certification programme has no published version.',
                422,
            ));
        }

        $version = CertificationProgrammeVersion::query()
            ->whereKey($programme->current_published_version_id)
            ->first();

        if (
            $version === null
            || $version->status !== CertificationProgrammeVersionStatus::Published
            || (int) $version->programme_id !== (int) $programme->id
        ) {
            throw new HttpResponseException(ApiResponse::error(
                ApiErrorCode::BUSINESS_VALIDATION,
                'This certification programme has no purchasable published version.',
                422,
            ));
        }

        return $version;
    }

    private function assertNotAlreadyEnrolled(User $user, CertificationProgramme $programme): void
    {
        $exists = CertificationEnrollment::query()
            ->where('user_id', $user->id)
            ->where('programme_id', $programme->id)
            ->exists();

        if ($exists) {
            throw new HttpResponseException(ApiResponse::error(
                ApiErrorCode::CONFLICT,
                'You are already enrolled in this certification programme.',
                409,
            ));
        }
    }

    private function markPaid(PlatformPayment $payment, ?string $providerReference): void
    {
        $payment->status = PlatformPaymentStatus::Paid;
        $payment->provider_reference = $providerReference ?? $payment->provider_reference;
        $payment->paid_at = $payment->paid_at ?? now();
        $payment->failed_at = null;
        $payment->save();
    }

    /**
     * After a unique-constraint race, the confirming transaction rolled back.
     * Persist provider success on this payment and return the winning enrollment.
     */
    private function reconcileAfterEnrollmentConflict(string $reference, ?string $providerReference): CertificationEnrollment
    {
        return DB::transaction(function () use ($reference, $providerReference): CertificationEnrollment {
            $payment = PlatformPayment::query()
                ->where('reference', $reference)
                ->lockForUpdate()
                ->first();

            if (
                $payment === null
                || $payment->purpose !== PlatformPaymentPurpose::CertificationEnrollment
            ) {
                throw new HttpResponseException(ApiResponse::error(
                    ApiErrorCode::NOT_FOUND,
                    'Payment reference was not found.',
                    404,
                ));
            }

            $existing = CertificationEnrollment::query()
                ->where('platform_payment_id', $payment->id)
                ->first();

            if ($existing === null) {
                $existing = CertificationEnrollment::query()
                    ->where('user_id', $payment->user_id)
                    ->where('programme_id', $payment->certification_programme_id)
                    ->lockForUpdate()
                    ->first();
            }

            if ($existing === null) {
                throw new HttpResponseException(ApiResponse::error(
                    ApiErrorCode::CONFLICT,
                    'An enrollment already exists for this certification programme.',
                    409,
                ));
            }

            $this->markPaid($payment, $providerReference);

            return $existing->load(['programme', 'programmeVersion', 'payment']);
        });
    }

    private function markUnsuccessful(PlatformPayment $payment, string $reason): void
    {
        if ($payment->status === PlatformPaymentStatus::Paid) {
            return;
        }

        $payment->status = $reason === 'abandoned' || str_contains($reason, 'abandon')
            ? PlatformPaymentStatus::Cancelled
            : PlatformPaymentStatus::Failed;
        $payment->failed_at = now();
        $payment->save();
    }

    private function assertAmbassador(User $user): void
    {
        if (! $user->isAmbassador()) {
            throw new AuthorizationException('You are not authorized to perform this action.');
        }
    }

    private function assertEnrollmentOwner(User $user, CertificationEnrollment $enrollment): void
    {
        if ((int) $enrollment->user_id !== (int) $user->id) {
            throw new HttpResponseException(ApiResponse::error(
                ApiErrorCode::NOT_FOUND,
                'The requested resource was not found.',
                404,
            ));
        }
    }

    /**
     * @param  array<string, mixed>|null  $payload
     */
    private function recordEvent(
        User $actor,
        CertificationProgramme $programme,
        CertificationProgrammeVersion $version,
        CertificationAdminEventAction $action,
        ?array $payload = null,
    ): void {
        $event = new CertificationAdminEvent;
        $event->actor_user_id = $actor->id;
        $event->programme_id = $programme->id;
        $event->programme_version_id = $version->id;
        $event->action = $action;
        $event->payload = $payload;
        $event->save();
    }
}
