<?php

namespace App\Http\Requests\Api\V1\Commissions;

use App\Http\Requests\ApiFormRequest;

class ConfirmCommissionReceivedRequest extends ApiFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'amount' => ['prohibited'],
            'currency' => ['prohibited'],
            'due_at' => ['prohibited'],
            'commission_amount' => ['prohibited'],
            'status' => ['prohibited'],
            'paid_at' => ['prohibited'],
            'received_at' => ['prohibited'],
            'payment_reference' => ['prohibited'],
            'payment_note' => ['prohibited'],
            'business_user_id' => ['prohibited'],
            'ambassador_user_id' => ['prohibited'],
            'deal_id' => ['prohibited'],
        ];
    }
}
