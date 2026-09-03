<?php

namespace App\Http\Requests\Api\V1\Deals;

use App\Http\Requests\ApiFormRequest;

class RejectPaymentEvidenceRequest extends ApiFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:3', 'max:5000'],
            'status' => ['prohibited'],
            'commission_amount' => ['prohibited'],
            'deal_status' => ['prohibited'],
        ];
    }
}
