<?php

namespace App\Http\Requests\Api\V1\Marketplace;

use App\Enums\CampaignStatus;
use App\Enums\CommissionType;
use App\Http\Requests\ApiFormRequest;
use Illuminate\Validation\Rule;

class MarketplaceCampaignIndexRequest extends ApiFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'q' => ['sometimes', 'nullable', 'string', 'max:100'],
            'category_id' => ['sometimes', 'integer', 'exists:categories,id'],
            'commission_type' => ['sometimes', Rule::enum(CommissionType::class)],
            'service_area' => ['sometimes', 'nullable', 'string', 'max:255'],
            'status' => ['sometimes', Rule::in([CampaignStatus::Active->value, CampaignStatus::Expiring->value])],
            'verified' => ['sometimes', 'boolean'],
            'featured' => ['sometimes', 'boolean'],
            'price_min' => ['sometimes', 'numeric', 'min:0'],
            'price_max' => ['sometimes', 'numeric', 'min:0'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:'.(int) config('api.pagination.max_per_page')],
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('verified')) {
            $this->merge([
                'verified' => filter_var($this->input('verified'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE),
            ]);
        }

        if ($this->has('featured')) {
            $this->merge([
                'featured' => filter_var($this->input('featured'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE),
            ]);
        }
    }
}
