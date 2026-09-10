<?php

namespace App\Http\Resources\Api\V1;

use App\Enums\CampaignVersionStatus;
use App\Models\Campaign;
use App\Models\CampaignVersion;
use App\Support\Campaigns\CampaignCoverPresentation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Campaign
 */
class AdminCampaignResource extends JsonResource
{
    public function __construct($resource, private readonly bool $includeCommercialTerms = false)
    {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $payload = array_merge((new CampaignResource($this->resource))->toArray($request), [
            'user' => $this->whenLoaded('user', fn () => [
                'id' => $this->user->id,
                'name' => $this->user->name,
                'email' => $this->user->email,
                'role' => $this->user->role->value,
            ]),
        ]);

        $payload['cover_image'] = CampaignCoverPresentation::managed(
            $this->resource,
            CampaignCoverPresentation::adminDownloadUrl((int) $this->id),
        );

        if ($this->includeCommercialTerms && $this->relationLoaded('currentVersion')) {
            $payload['current_version'] = $this->adminCurrentVersion($this->currentVersion);
        }

        return $payload;
    }

    /**
     * Admin detail exposes published commercial terms on the campaign's current version.
     * Unpublished/draft current versions keep identity only (no commercial payload).
     * Sensitive payment identifiers follow marketplace redaction (omitted).
     *
     * @return array<string, mixed>|null
     */
    private function adminCurrentVersion(?CampaignVersion $version): ?array
    {
        if ($version === null) {
            return null;
        }

        $identity = [
            'id' => $version->id,
            'version_number' => $version->version_number,
            'status' => $version->status->value,
        ];

        if ($version->status !== CampaignVersionStatus::Published) {
            return $identity;
        }

        return [
            ...$identity,
            'product_name' => $version->product_name,
            'product_description' => $version->product_description,
            'pricing_method' => $version->pricing_method?->value,
            'price_amount' => $version->price_amount,
            'price_currency' => $version->price_currency,
            'service_area' => $version->service_area,
            'commission_type' => $version->commission_type?->value,
            'commission_rate' => $version->commission_rate,
            'commission_amount' => $version->commission_amount,
            'commission_trigger' => $version->commission_trigger?->value,
            'commission_trigger_description' => $version->commission_trigger_description,
            'commission_payment_deadline_days' => $version->commission_payment_deadline_days,
            'minimum_qualifying_amount' => $version->minimum_qualifying_amount,
            'qualifying_conditions' => $version->qualifying_conditions,
            'refund_cancellation_rules' => $version->refund_cancellation_rules,
            'approved_claims' => $version->approved_claims,
            'prohibited_claims' => $version->prohibited_claims,
            'brand_use_rules' => $version->brand_use_rules,
            'geographic_customer_restrictions' => $version->geographic_customer_restrictions,
            'approved_copy' => $version->approved_copy,
            'marketing_links' => $version->marketing_links ?? [],
            'payment_destination_name' => $version->payment_destination_name,
            'payment_provider' => $version->payment_provider,
            'terms' => $version->terms,
            'published_at' => $version->published_at?->toIso8601String(),
        ];
    }
}
