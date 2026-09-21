<?php

namespace App\Services\Campaigns;

use App\Contracts\Payments\PlatformPaymentGateway;
use App\Enums\CampaignStatus;
use App\Enums\PlatformPaymentPurpose;
use App\Enums\PlatformPaymentStatus;
use App\Models\Campaign;
use App\Models\CampaignExtension;
use App\Models\CampaignExtensionPackage;
use App\Models\PlatformPayment;
use App\Models\User;
use App\Support\Api\ApiErrorCode;
use App\Support\Api\ApiResponse;
use App\Support\Payments\ParticipantPaymentMessages;
use App\Support\Payments\PaystackReturnUrl;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class CampaignExtensionService
{
    public function __construct(
        private readonly CampaignLifecycleService $lifecycle,
        private readonly PlatformPaymentGateway $gateway,
    ) {}

    /**
     * @return Collection<int, CampaignExtensionPackage>
     */
    public function listPackages(User $user, Campaign $campaign): Collection
    {
        $this->assertOwner($user, $campaign);

        return CampaignExtensionPackage::query()->active()->get();
    }

    /**
     * @return Collection<int, CampaignExtension>
     */
    public function history(User $user, Campaign $campaign): Collection
    {
        $this->assertOwner($user, $campaign);

        return $campaign->extensions()->with('payment')->orderByDesc('id')->get();
    }

    /**
     * @return Collection<int, CampaignExtension>
     */
    public function adminHistory(User $admin, Campaign $campaign): Collection
    {
        if (! $admin->isAdmin()) {
            throw new AuthorizationException('You are not authorized to perform this action.');
        }

        return $campaign->extensions()->with('payment')->orderByDesc('id')->get();
    }

    /**
     * @return array{payment: PlatformPayment, authorization_url: string, access_code: ?string}
     */
    public function initialize(User $user, Campaign $campaign, int $packageId): array
    {
        $this->assertOwner($user, $campaign);
        $this->assertEligible($campaign);

        $package = CampaignExtensionPackage::query()
            ->whereKey($packageId)
            ->where('is_active', true)
            ->first();

        if ($package === null) {
            throw new HttpResponseException(ApiResponse::error(
                ApiErrorCode::BUSINESS_VALIDATION,
                'This extension package is not available.',
                422,
            ));
        }

        if ($package->amount_minor < 1 || $package->duration_days < 1) {
            throw new HttpResponseException(ApiResponse::error(
                ApiErrorCode::BUSINESS_VALIDATION,
                'This extension package is not priced for purchase.',
                422,
            ));
        }

        $secret = (string) config('paystack.secret_key');

        if ($secret === '') {
            Log::warning('Extension purchase initialize blocked: platform payment provider is not configured');

            throw new HttpResponseException(ApiResponse::error(
                ApiErrorCode::SERVICE_UNAVAILABLE,
                ParticipantPaymentMessages::TEMPORARILY_UNAVAILABLE,
                503,
            ));
        }

        $reference = 'mh_ext_'.strtolower((string) Str::ulid());
        $callback = PaystackReturnUrl::businessCampaign($campaign->id);

        $initialization = $this->gateway->initialize(
            $user->email,
            $package->amount_minor,
            $package->currency,
            $reference,
            $callback,
            [
                'purpose' => PlatformPaymentPurpose::CampaignExtension->value,
                'campaign_id' => $campaign->id,
                'package_id' => $package->id,
            ],
        );

        $payment = new PlatformPayment;
        $payment->user_id = $user->id;
        $payment->campaign_id = $campaign->id;
        $payment->campaign_extension_package_id = $package->id;
        $payment->purpose = PlatformPaymentPurpose::CampaignExtension;
        $payment->provider = 'paystack';
        $payment->reference = $initialization->reference;
        $payment->amount_minor = $package->amount_minor;
        $payment->currency = $package->currency;
        $payment->duration_days = $package->duration_days;
        $payment->status = PlatformPaymentStatus::Pending;
        $payment->authorization_url = $initialization->authorizationUrl;
        $payment->save();

        return [
            'payment' => $payment->fresh(),
            'authorization_url' => $initialization->authorizationUrl,
            'access_code' => $initialization->accessCode,
        ];
    }

    public function confirmOwnedReference(User $user, Campaign $campaign, string $reference): CampaignExtension
    {
        $this->assertOwner($user, $campaign);

        $payment = PlatformPayment::query()->where('reference', $reference)->first();

        if (
            $payment === null
            || $payment->campaign_id !== $campaign->id
            || $payment->user_id !== $user->id
            || $payment->purpose !== PlatformPaymentPurpose::CampaignExtension
        ) {
            throw new HttpResponseException(ApiResponse::error(
                ApiErrorCode::NOT_FOUND,
                'Payment reference was not found for this campaign.',
                404,
            ));
        }

        return $this->confirmReference($reference);
    }

    public function confirmReference(string $reference): CampaignExtension
    {
        $verification = $this->gateway->verify($reference);

        $outcome = DB::transaction(function () use ($reference, $verification): array {
            $payment = PlatformPayment::query()
                ->where('reference', $reference)
                ->lockForUpdate()
                ->first();

            if ($payment === null) {
                return ['error' => 'not_found'];
            }

            if ($payment->purpose !== PlatformPaymentPurpose::CampaignExtension) {
                return ['error' => 'wrong_purpose'];
            }

            $existing = CampaignExtension::query()
                ->where('platform_payment_id', $payment->id)
                ->first();

            if ($existing !== null) {
                return ['extension' => $existing->load(['payment', 'campaign'])];
            }

            if (! $verification->isSuccessful()) {
                $this->markUnsuccessful($payment, $verification->status);

                return ['error' => 'not_successful'];
            }

            if (
                $verification->amountMinor !== $payment->amount_minor
                || strtoupper($verification->currency) !== strtoupper($payment->currency)
            ) {
                Log::warning('Platform payment amount or currency mismatch', [
                    'reference' => $payment->reference,
                    'expected_amount_minor' => $payment->amount_minor,
                    'expected_currency' => $payment->currency,
                ]);

                $this->markUnsuccessful($payment, 'mismatch');

                return ['error' => 'mismatch'];
            }

            $campaign = Campaign::query()
                ->whereKey($payment->campaign_id)
                ->lockForUpdate()
                ->firstOrFail();

            $applied = $this->lifecycle->applyPaidExtension(
                $campaign,
                (int) $payment->duration_days,
            );

            $extension = new CampaignExtension;
            $extension->campaign_id = $campaign->id;
            $extension->user_id = $payment->user_id;
            $extension->campaign_extension_package_id = $payment->campaign_extension_package_id;
            $extension->platform_payment_id = $payment->id;
            $extension->duration_days = (int) $payment->duration_days;
            $extension->amount_minor = $payment->amount_minor;
            $extension->currency = $payment->currency;
            $extension->previous_listing_expires_at = $applied['previous_listing_expires_at'];
            $extension->resulting_listing_expires_at = $applied['resulting_listing_expires_at'];
            $extension->previous_status = $applied['previous_status'];
            $extension->resulting_status = CampaignStatus::Active;
            $extension->applied_at = now();
            $extension->save();

            $payment->status = PlatformPaymentStatus::Paid;
            $payment->provider_reference = $verification->providerReference;
            $payment->paid_at = now();
            $payment->failed_at = null;
            $payment->save();

            return ['extension' => $extension->fresh()->load(['payment', 'campaign'])];
        });

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
                'Payment details did not match the initialized extension.',
                422,
            ));
        }

        return $outcome['extension'];
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
                    || $payment->purpose !== PlatformPaymentPurpose::CampaignExtension
                    || $payment->status === PlatformPaymentStatus::Paid
                ) {
                    return;
                }

                $this->markUnsuccessful($payment, $event);
            });
        }
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

    private function assertEligible(Campaign $campaign): void
    {
        $eligible = [
            CampaignStatus::Active,
            CampaignStatus::Expiring,
            CampaignStatus::Expired,
        ];

        if (! in_array($campaign->status, $eligible, true)) {
            throw new HttpResponseException(ApiResponse::error(
                ApiErrorCode::CONFLICT,
                'This campaign cannot be extended in its current state.',
                409,
            ));
        }
    }

    private function assertOwner(User $user, Campaign $campaign): void
    {
        if (! $user->isBusiness() || $campaign->user_id !== $user->id) {
            throw new AuthorizationException('You are not authorized to perform this action.');
        }
    }
}
