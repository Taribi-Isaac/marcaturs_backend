<?php

namespace App\Http\Resources\Api\V1;

use App\Models\PaymentEvidence;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin PaymentEvidence
 */
class PaymentEvidenceResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'deal_id' => $this->deal_id,
            'kind' => $this->kind->value,
            'status' => $this->status->value,
            'reference_number' => $this->reference_number,
            'amount' => $this->amount,
            'currency' => $this->currency,
            'paid_on' => $this->paid_on?->toDateString(),
            'note' => $this->note,
            'has_file' => $this->hasFile(),
            'original_filename' => $this->original_filename,
            'mime_type' => $this->mime_type,
            'size_bytes' => $this->size_bytes,
            'submitted_by' => $this->userPayload($this->ambassador),
            'submitted_at' => $this->submitted_at?->toIso8601String(),
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
