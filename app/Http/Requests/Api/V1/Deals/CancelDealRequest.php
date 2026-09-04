<?php

namespace App\Http\Requests\Api\V1\Deals;

use App\Http\Requests\ApiFormRequest;

class CancelDealRequest extends ApiFormRequest
{
    protected function prepareForValidation(): void
    {
        if ($this->exists('reason') && is_string($this->input('reason'))) {
            $this->merge([
                'reason' => trim($this->input('reason')),
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:3', 'max:5000'],
            'status' => ['prohibited'],
            'cancelled_at' => ['prohibited'],
            'business_user_id' => ['prohibited'],
            'ambassador_user_id' => ['prohibited'],
            'commission_amount' => ['prohibited'],
        ];
    }
}
