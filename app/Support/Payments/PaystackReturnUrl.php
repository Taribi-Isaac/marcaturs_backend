<?php

namespace App\Support\Payments;

/**
 * Browser return URLs after Paystack checkout.
 *
 * Prefer FRONTEND_URL + product path so local/staging/production each work without
 * hard-coding localhost. Optional PAYSTACK_CALLBACK_URL remains a full-URL override
 * when FRONTEND_URL is unset (legacy). Never use the Paystack webhook path here.
 */
final class PaystackReturnUrl
{
    public static function certificationPurchase(): ?string
    {
        return self::forPath('/app/ambassador/certification/purchase/return');
    }

    public static function businessCampaign(int $campaignId): ?string
    {
        return self::forPath('/app/business/campaigns/'.$campaignId);
    }

    public static function forPath(string $path): ?string
    {
        $frontend = rtrim((string) config('paystack.frontend_url', ''), '/');

        if ($frontend !== '') {
            return $frontend.'/'.ltrim($path, '/');
        }

        $override = config('paystack.callback_url');

        if (is_string($override) && $override !== '') {
            return $override;
        }

        return null;
    }
}
