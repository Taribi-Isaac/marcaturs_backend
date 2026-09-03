<?php

namespace App\Http\Requests\Api\V1\Deals;

use App\Http\Requests\ApiFormRequest;

class StoreDealRequest extends ApiFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'campaign_id' => ['required', 'integer'],
            'expected_transaction_amount' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'business_id' => ['prohibited'],
            'business_user_id' => ['prohibited'],
            'ambassador_id' => ['prohibited'],
            'campaign_version_id' => ['prohibited'],
            'status' => ['prohibited'],
            'commission_type' => ['prohibited'],
            'commission_rate' => ['prohibited'],
            'commission_amount' => ['prohibited'],
            'commission_trigger' => ['prohibited'],
            'commission_payment_deadline_days' => ['prohibited'],
            'customer_user_id' => ['prohibited'],
            'customer_email' => ['prohibited'],
            'customer_phone' => ['prohibited'],
            'conversation_id' => ['prohibited'],
        ];
    }
}
