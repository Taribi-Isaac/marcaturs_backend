<?php

namespace App\Http\Requests\Api\V1\Deals;

use App\Http\Requests\ApiFormRequest;

class ConfirmDealRequest extends ApiFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'confirmed_payment_amount' => ['sometimes', 'nullable', 'numeric', 'gt:0'],
            'commission_amount' => ['prohibited'],
            'commission_rate' => ['prohibited'],
            'commission_type' => ['prohibited'],
            'status' => ['prohibited'],
            'confirmed_at' => ['prohibited'],
            'business_user_id' => ['prohibited'],
            'ambassador_user_id' => ['prohibited'],
            'campaign_id' => ['prohibited'],
            'campaign_version_id' => ['prohibited'],
            'payment_evidence_id' => ['prohibited'],
            'expected_transaction_amount' => ['prohibited'],
        ];
    }
}
