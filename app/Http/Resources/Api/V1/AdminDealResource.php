<?php

namespace App\Http\Resources\Api\V1;

use App\Enums\CampaignVersionStatus;
use App\Models\CampaignVersion;
use App\Models\Deal;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Deal
 */
class AdminDealResource extends JsonResource
{
    public function __construct($resource, private readonly bool $detailed = false)
    {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $payload = [
            'id' => $this->id,
            'status' => $this->status->value,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'business' => $this->partyPayload($this->business),
            'ambassador' => $this->partyPayload($this->ambassador),
            'campaign' => [
                'id' => $this->campaign?->id,
                'title' => $this->campaign?->title,
                'status' => $this->campaign?->status?->value,
            ],
            'campaign_version' => [
                'id' => $this->campaign_version_id,
                'version_number' => $this->campaignVersion?->version_number,
            ],
            'product_name' => $this->product_name,
            'commission_type' => $this->commission_type->value,
            'commission_amount' => $this->commission_amount,
            'confirmed_payment_amount' => $this->confirmed_payment_amount,
            'confirmed_at' => $this->confirmed_at?->toIso8601String(),
            'has_evidence' => (bool) ($this->has_evidence ?? (($this->evidence_count ?? 0) > 0)),
            'evidence_count' => (int) ($this->evidence_count ?? 0),
            'latest_evidence_status' => $this->latest_evidence_status,
            'commission' => $this->relationLoaded('commission')
                ? $this->commissionSummary($this->commission, thin: ! $this->detailed)
                : null,
            'open_dispute_count' => (int) ($this->open_dispute_count ?? 0),
        ];

        if (! $this->detailed) {
            return $payload;
        }

        $payload['cancelled_at'] = $this->cancelled_at?->toIso8601String();
        $payload['business'] = $this->partyPayload($this->business, withRole: true);
        $payload['ambassador'] = $this->partyPayload($this->ambassador, withRole: true);
        $payload['campaign'] = [
            'id' => $this->campaign?->id,
            'title' => $this->campaign?->title,
            'status' => $this->campaign?->status?->value,
            'category' => $this->campaign?->relationLoaded('category') && $this->campaign->category !== null
                ? (new CategoryResource($this->campaign->category))->resolve($request)
                : null,
        ];
        $payload['campaign_version'] = $this->boundVersionPayload($this->campaignVersion);
        $payload['snapshot'] = [
            'product_name' => $this->product_name,
            'pricing_method' => $this->pricing_method?->value,
            'price_amount' => $this->price_amount,
            'price_currency' => $this->price_currency,
            'commission_type' => $this->commission_type->value,
            'commission_rate' => $this->commission_rate,
            'commission_amount' => $this->commission_amount,
            'commission_trigger' => $this->commission_trigger->value,
            'commission_trigger_description' => $this->commission_trigger_description,
            'commission_payment_deadline_days' => $this->commission_payment_deadline_days,
            'minimum_qualifying_amount' => $this->minimum_qualifying_amount,
            'qualifying_conditions' => $this->qualifying_conditions,
            'expected_transaction_amount' => $this->expected_transaction_amount,
            'confirmed_payment_amount' => $this->confirmed_payment_amount,
            'confirmed_at' => $this->confirmed_at?->toIso8601String(),
        ];
        $payload['payment_evidence'] = $this->relationLoaded('paymentEvidences')
            ? PaymentEvidenceResource::collection($this->paymentEvidences)->resolve($request)
            : [];
        $payload['commission'] = $this->commissionSummary($this->commission, thin: false);
        $payload['disputes'] = $this->relationLoaded('disputes')
            ? $this->disputes->map(fn ($dispute) => [
                'id' => $dispute->id,
                'reference' => $dispute->reference,
                'status' => $dispute->status->value,
                'category' => $dispute->category === null ? null : [
                    'id' => $dispute->category->id,
                    'code' => $dispute->category->code,
                    'name' => $dispute->category->name,
                ],
            ])->values()->all()
            : [];
        $payload['events'] = $this->relationLoaded('events')
            ? DealEventResource::collection($this->events)->resolve($request)
            : [];

        return $payload;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function partyPayload(?User $user, bool $withRole = false): ?array
    {
        if ($user === null) {
            return null;
        }

        $payload = [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'status' => $user->status->value,
        ];

        if ($withRole) {
            $payload['role'] = $user->role->value;
        }

        return $payload;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function commissionSummary($commission, bool $thin): ?array
    {
        if ($commission === null) {
            return null;
        }

        $payload = [
            'status' => $commission->status->value,
            'due_at' => $commission->due_at?->toIso8601String(),
            'is_overdue' => $commission->isOverdue(),
        ];

        if ($thin) {
            return $payload;
        }

        return [
            'id' => $commission->id,
            'status' => $commission->status->value,
            'amount' => $commission->amount,
            'currency' => $commission->currency,
            'commission_type' => $commission->commission_type->value,
            'commission_rate' => $commission->commission_rate,
            'became_due_at' => $commission->became_due_at?->toIso8601String(),
            'due_at' => $commission->due_at?->toIso8601String(),
            'paid_at' => $commission->paid_at?->toIso8601String(),
            'received_at' => $commission->received_at?->toIso8601String(),
            'is_overdue' => $commission->isOverdue(),
            'payment_reference' => $commission->payment_reference,
            'payment_note' => $commission->payment_note,
        ];
    }

    /**
     * Bound Campaign Version commercial terms follow MH-BE-036 Admin redaction.
     *
     * @return array<string, mixed>|null
     */
    private function boundVersionPayload(?CampaignVersion $version): ?array
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
