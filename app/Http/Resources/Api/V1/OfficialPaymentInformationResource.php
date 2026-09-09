<?php

namespace App\Http\Resources\Api\V1;

use App\Enums\OverallVerificationStatus;
use App\Models\Campaign;
use App\Support\Campaigns\OfficialPaymentShare;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Public Official Payment Information page (MH-BE-042).
 *
 * Source of truth: published current Campaign Version payment destination fields.
 * MarcatursHub does not receive, hold, or process the customer purchase payment.
 *
 * @mixin Campaign
 */
class OfficialPaymentInformationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $version = $this->currentVersion;
        $profile = $this->user?->businessProfile;
        $share = OfficialPaymentShare::references($this->resource);

        return [
            'financial_boundary' => [
                'customer_pays' => 'business',
                'platform_holds_customer_funds' => false,
                'statement' => 'Customers pay the Business directly. MarcatursHub does not receive, hold, route, escrow, or process this purchase payment.',
            ],
            'share' => $share,
            'business' => [
                'legal_name' => $profile?->legal_name,
                'trading_name' => $profile?->trading_name,
                'operating_location' => $profile?->operating_location,
                'website' => $profile?->website,
                'verification_status' => ($this->marketplace_verification_status ?? OverallVerificationStatus::NotStarted)->value,
            ],
            'campaign' => [
                'id' => $this->id,
                'title' => $this->title,
                'status' => $this->status->value,
                'category' => $this->category ? [
                    'id' => $this->category->id,
                    'name' => $this->category->name,
                    'slug' => $this->category->slug,
                ] : null,
            ],
            'campaign_version' => [
                'version_number' => $version?->version_number,
                'status' => $version?->status->value,
                'published_at' => $version?->published_at?->toIso8601String(),
                'product_name' => $version?->product_name,
                'product_description' => $version?->product_description,
                'service_area' => $version?->service_area,
                'pricing_method' => $version?->pricing_method?->value,
                'price_amount' => $version?->price_amount,
                'price_currency' => $version?->price_currency,
            ],
            'payment_destination' => [
                'destination_name' => $version?->payment_destination_name,
                'provider' => $version?->payment_provider,
                'account_identifier' => $version?->payment_account_identifier,
                'instructions' => $version?->payment_instructions,
                'contact' => $version?->payment_contact,
            ],
        ];
    }
}
