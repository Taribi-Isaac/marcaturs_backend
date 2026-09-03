<?php

namespace App\Http\Resources\Api\V1;

use App\Models\PlatformPayment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin PlatformPayment
 */
class PlatformPaymentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'purpose' => $this->purpose->value,
            'provider' => $this->provider,
            'status' => $this->status->value,
            'amount_minor' => $this->amount_minor,
            'currency' => $this->currency,
            'duration_days' => $this->duration_days,
            'paid_at' => $this->paid_at?->toIso8601String(),
        ];
    }
}
