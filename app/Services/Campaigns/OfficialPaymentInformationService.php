<?php

namespace App\Services\Campaigns;

use App\Models\Campaign;
use App\Support\Campaigns\OfficialPaymentShare;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class OfficialPaymentInformationService
{
    /**
     * Resolve a publicly shareable payment page by opaque token.
     *
     * Eligibility mirrors marketplace discoverability: active/expiring campaign,
     * published current version, assignable category, active Business owner.
     * Invalid tokens and ineligible campaigns both yield ModelNotFoundException (404).
     */
    public function resolveByToken(string $token): Campaign
    {
        $normalized = strtolower(trim($token));

        if ($normalized === '' || strlen($normalized) > 64 || ! preg_match('/^[a-z0-9]+$/', $normalized)) {
            throw new ModelNotFoundException;
        }

        $campaign = Campaign::query()
            ->discoverable()
            ->where('official_payment_token', $normalized)
            ->with(['category', 'currentVersion', 'user.businessProfile'])
            ->first();

        if ($campaign === null) {
            throw new ModelNotFoundException;
        }

        OfficialPaymentShare::ensureToken($campaign);

        return $campaign;
    }
}
