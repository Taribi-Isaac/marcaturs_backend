<?php

namespace App\Http\Resources\Api\V1;

use App\Models\Deal;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Deal
 */
class DealResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status->value,
            'business' => $this->userPayload($this->business),
            'ambassador' => $this->userPayload($this->ambassador),
            'campaign' => [
                'id' => $this->campaign?->id,
                'title' => $this->campaign?->title,
                'status' => $this->campaign?->status->value,
            ],
            'campaign_version' => [
                'id' => $this->campaign_version_id,
                'version_number' => $this->campaignVersion?->version_number,
            ],
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
            'commission' => $this->whenLoaded('commission', fn () => $this->commission === null ? null : [
                'id' => $this->commission->id,
                'status' => $this->commission->status->value,
                'amount' => $this->commission->amount,
                'currency' => $this->commission->currency,
                'due_at' => $this->commission->due_at?->toIso8601String(),
                'paid_at' => $this->commission->paid_at?->toIso8601String(),
                'received_at' => $this->commission->received_at?->toIso8601String(),
                'is_overdue' => $this->commission->isOverdue(),
            ]),
            'events' => $this->whenLoaded('events', fn () => DealEventResource::collection($this->events)->resolve($request)),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function userPayload(?User $user): ?array
    {
        if ($user === null) {
            return null;
        }

        return [
            'id' => $user->id,
            'name' => $user->name,
            'role' => $user->role->value,
        ];
    }
}
