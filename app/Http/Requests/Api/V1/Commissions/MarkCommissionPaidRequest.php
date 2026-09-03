<?php

namespace App\Http\Requests\Api\V1\Commissions;

use App\Http\Requests\ApiFormRequest;

class MarkCommissionPaidRequest extends ApiFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'payment_reference' => ['sometimes', 'nullable', 'string', 'max:128'],
            'payment_note' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'amount' => ['prohibited'],
            'currency' => ['prohibited'],
            'due_at' => ['prohibited'],
            'became_due_at' => ['prohibited'],
            'commission_amount' => ['prohibited'],
            'commission_rate' => ['prohibited'],
            'commission_type' => ['prohibited'],
            'status' => ['prohibited'],
            'paid_at' => ['prohibited'],
            'received_at' => ['prohibited'],
            'business_user_id' => ['prohibited'],
            'ambassador_user_id' => ['prohibited'],
            'deal_id' => ['prohibited'],
            'campaign_version_id' => ['prohibited'],
        ];
    }
}
