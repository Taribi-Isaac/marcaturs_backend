<?php

namespace App\Services\Campaigns;

use App\Contracts\Payments\PlatformPaymentGateway;
use App\Enums\CampaignStatus;
use App\Enums\CampaignVersionStatus;
use App\Enums\PlatformPaymentPurpose;
use App\Enums\PlatformPaymentStatus;
use App\Models\Campaign;
use App\Models\CampaignFeaturedPackage;
use App\Models\CampaignFeaturedPurchase;
use App\Models\PlatformPayment;
use App\Models\User;
use App\Services\Notifications\CampaignFeaturedNotificationDispatcher;
use App\Support\Api\ApiErrorCode;
use App\Support\Api\ApiResponse;
use App\Support\Payments\ParticipantPaymentMessages;
use App\Support\Payments\PaystackReturnUrl;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class CampaignFeaturedService
{
    public function __construct(
        private readonly PlatformPaymentGateway $gateway,
        private readonly CampaignFeaturedNotificationDispatcher $notifications,
    ) {}

    /**
     * @return Collection<int, CampaignFeaturedPackage>
     */
    public function listActivePackages(): Collection
    {
        return CampaignFeaturedPackage::query()->active()->get();
    }

    /**
     * @return array{
     *     is_featured: bool,
     *     expires_at: ?string,
     *     purchases: Collection<int, CampaignFeaturedPurchase>
     * }
     */
    public function status(User $user, Campaign $campaign): array
    {
        $this->assertOwner($user, $campaign);
        $this->syncCampaignFeaturedFlag($campaign);

        $purchases = $campaign->featuredPurchases()
            ->with('payment')
            ->orderByDesc('id')
            ->get();

        $active = $purchases->first(fn (CampaignFeaturedPurchase $purchase) => $purchase->isCurrentlyActive());

        return [
            'is_featured' => $active !== null,
            'expires_at' => $active?->expires_at?->toIso8601String(),
            'purchases' => $purchases,
        ];
    }

    /**
     * @return Collection<int, CampaignFeaturedPurchase>
     */
    public function adminHistory(User $admin, Campaign $campaign): Collection
    {
        if (! $admin->isAdmin()) {
            throw new AuthorizationException('You are not authorized to perform this action.');
        }

        return $campaign->featuredPurchases()->with('payment')->orderByDesc('id')->get();
    }

    /**
     * @return array{payment: PlatformPayment, authorization_url: string, access_code: ?string}
     */
    public function initialize(User $user, Campaign $campaign, int $packageId): array
    {
        $this->assertOwner($user, $campaign);
        $this->assertEligible($campaign);

        $package = CampaignFeaturedPackage::query()
            ->whereKey($packageId)
            ->where('is_active', true)
            ->first();

        if ($package === null) {
            throw new HttpResponseException(ApiResponse::error(
                ApiErrorCode::BUSINESS_VALIDATION,
                'This Featured package is not available.',
                422,
            ));
        }

        if ($package->amount_minor < 1 || $package->duration_days < 1) {
            throw new HttpResponseException(ApiResponse::error(
                ApiErrorCode::BUSINESS_VALIDATION,
                'This Featured package is not priced for purchase.',
                422,
            ));
        }

        $secret = (string) config('paystack.secret_key');

        if ($secret === '') {
            Log::warning('Featured purchase initialize blocked: platform payment provider is not configured');

            throw new HttpResponseException(ApiResponse::error(
                ApiErrorCode::SERVICE_UNAVAILABLE,
                ParticipantPaymentMessages::TEMPORARILY_UNAVAILABLE,
                503,
            ));
        }

        $reference = 'mh_feat_'.strtolower((string) Str::ulid());
        $callback = PaystackReturnUrl::businessCampaign($campaign->id);

        $initialization = $this->gateway->initialize(
            $user->email,
            $package->amount_minor,
            $package->currency,
            $reference,
            $callback,
            [
                'purpose' => PlatformPaymentPurpose::CampaignFeatured->value,
                'campaign_id' => $campaign->id,
                'package_id' => $package->id,
            ],
        );

        $payment = new PlatformPayment;
        $payment->user_id = $user->id;
        $payment->campaign_id = $campaign->id;
        $payment->campaign_featured_package_id = $package->id;
        $payment->purpose = PlatformPaymentPurpose::CampaignFeatured;
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

    public function confirmOwnedReference(User $user, Campaign $campaign, string $reference): CampaignFeaturedPurchase
    {
        $this->assertOwner($user, $campaign);

        $payment = PlatformPayment::query()->where('reference', $reference)->first();

        if (
            $payment === null
            || $payment->campaign_id !== $campaign->id
            || $payment->user_id !== $user->id
            || $payment->purpose !== PlatformPaymentPurpose::CampaignFeatured
        ) {
            throw new HttpResponseException(ApiResponse::error(
                ApiErrorCode::NOT_FOUND,
                'Payment reference was not found for this campaign.',
                404,
            ));
        }

        return $this->confirmReference($reference);
    }

    public function confirmReference(string $reference): CampaignFeaturedPurchase
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

            if ($payment->purpose !== PlatformPaymentPurpose::CampaignFeatured) {
                return ['error' => 'wrong_purpose'];
            }

            $existing = CampaignFeaturedPurchase::query()
                ->where('platform_payment_id', $payment->id)
                ->first();

            if ($existing !== null) {
                return ['purchase' => $existing->load(['payment', 'campaign']), 'created' => false];
            }

            if (! $verification->isSuccessful()) {
                $this->markUnsuccessful($payment, $verification->status);

                return ['error' => 'not_successful'];
            }

            if (
                $verification->amountMinor !== $payment->amount_minor
                || strtoupper($verification->currency) !== strtoupper($payment->currency)
            ) {
                Log::warning('Featured platform payment amount or currency mismatch', [
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

            $this->assertEligible($campaign);

            $activatedAt = now();
            $currentExpiry = $this->currentFeaturedExpiresAt($campaign);
            $durationDays = (int) $payment->duration_days;

            if ($currentExpiry !== null && $currentExpiry->isFuture()) {
                $expiresAt = $currentExpiry->copy()->addDays($durationDays);
            } else {
                $expiresAt = $activatedAt->copy()->addDays($durationDays);
            }

            $package = CampaignFeaturedPackage::query()->find($payment->campaign_featured_package_id);

            $purchase = new CampaignFeaturedPurchase;
            $purchase->campaign_id = $campaign->id;
            $purchase->user_id = $payment->user_id;
            $purchase->campaign_featured_package_id = $payment->campaign_featured_package_id;
            $purchase->platform_payment_id = $payment->id;
            $purchase->package_name = $package?->name ?? 'Featured package';
            $purchase->duration_days = $durationDays;
            $purchase->amount_minor = $payment->amount_minor;
            $purchase->currency = $payment->currency;
            $purchase->activated_at = $activatedAt;
            $purchase->expires_at = $expiresAt;
            $purchase->save();

            $payment->status = PlatformPaymentStatus::Paid;
            $payment->provider_reference = $verification->providerReference;
            $payment->paid_at = now();
            $payment->failed_at = null;
            $payment->save();

            $campaign->is_featured = true;
            $campaign->save();

            return ['purchase' => $purchase->fresh()->load(['payment', 'campaign']), 'created' => true];
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
                'Payment details did not match the initialized Featured purchase.',
                422,
            ));
        }

        /** @var CampaignFeaturedPurchase $purchase */
        $purchase = $outcome['purchase'];

        if (($outcome['created'] ?? false) === true) {
            $this->notifications->notifyPurchased($purchase);
        }

        return $purchase;
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
                    || $payment->purpose !== PlatformPaymentPurpose::CampaignFeatured
                    || $payment->status === PlatformPaymentStatus::Paid
                ) {
                    return;
                }

                $this->markUnsuccessful($payment, $event);
            });
        }
    }

    public function clearExpiredFeaturedFlags(): int
    {
        $cleared = 0;

        $stale = Campaign::query()
            ->where('is_featured', true)
            ->whereDoesntHave('featuredPurchases', function ($query): void {
                $query->currentlyActive();
            })
            ->get();

        foreach ($stale as $campaign) {
            $campaign->is_featured = false;
            $campaign->save();
            $cleared++;
        }

        return $cleared;
    }

    public function syncCampaignFeaturedFlag(Campaign $campaign): void
    {
        $shouldBeFeatured = $campaign->featuredPurchases()->currentlyActive()->exists();

        if ((bool) $campaign->is_featured !== $shouldBeFeatured) {
            $campaign->is_featured = $shouldBeFeatured;
            $campaign->save();
        }
    }

    private function currentFeaturedExpiresAt(Campaign $campaign): ?Carbon
    {
        $expiresAt = $campaign->featuredPurchases()
            ->currentlyActive()
            ->orderByDesc('expires_at')
            ->value('expires_at');

        return $expiresAt !== null ? Carbon::parse($expiresAt) : null;
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
        ];

        if (! in_array($campaign->status, $eligible, true)) {
            throw new HttpResponseException(ApiResponse::error(
                ApiErrorCode::CONFLICT,
                'This campaign cannot purchase Featured visibility in its current state.',
                409,
            ));
        }

        $campaign->loadMissing(['currentVersion', 'category']);

        $current = $campaign->currentVersion;

        if ($current === null || $current->status !== CampaignVersionStatus::Published) {
            throw new HttpResponseException(ApiResponse::error(
                ApiErrorCode::BUSINESS_VALIDATION,
                'A published campaign version is required before purchasing Featured visibility.',
                422,
            ));
        }

        if ($campaign->category === null || ! $campaign->category->isAssignableToCampaigns()) {
            throw new HttpResponseException(ApiResponse::error(
                ApiErrorCode::BUSINESS_VALIDATION,
                'This campaign category cannot be used in the marketplace.',
                422,
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
