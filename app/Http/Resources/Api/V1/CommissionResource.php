<?php

namespace App\Http\Resources\Api\V1;

use App\Models\Commission;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Commission
 */
class CommissionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status->value,
            'deal_id' => $this->deal_id,
            'business' => $this->userPayload($this->business),
            'ambassador' => $this->userPayload($this->ambassador),
            'campaign_version_id' => $this->campaign_version_id,
            'commission_type' => $this->commission_type->value,
            'commission_rate' => $this->commission_rate,
            'amount' => $this->amount,
            'currency' => $this->currency,
            'became_due_at' => $this->became_due_at?->toIso8601String(),
            'due_at' => $this->due_at?->toIso8601String(),
            'paid_at' => $this->paid_at?->toIso8601String(),
            'received_at' => $this->received_at?->toIso8601String(),
            'payment_reference' => $this->payment_reference,
            'payment_note' => $this->payment_note,
            'is_overdue' => $this->isOverdue(),
            'created_at' => $this->created_at?->toIso8601String(),
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
            'role' => $user->role->value,
        ];
    }
}
