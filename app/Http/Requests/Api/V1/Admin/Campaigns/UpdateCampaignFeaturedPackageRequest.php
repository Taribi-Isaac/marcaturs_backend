<?php

namespace App\Http\Requests\Api\V1\Admin\Campaigns;

use App\Http\Requests\ApiFormRequest;

class UpdateCampaignFeaturedPackageRequest extends ApiFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'duration_days' => ['sometimes', 'integer', 'min:1', 'max:3650'],
            'amount_minor' => ['sometimes', 'integer', 'min:1'],
            'currency' => ['sometimes', 'string', 'size:3', 'in:NGN'],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:10000'],
        ];
    }
}
