<?php

namespace App\Http\Requests\Api\V1\Campaigns;

use App\Http\Requests\ApiFormRequest;

class UpdateCampaignRequest extends ApiFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'category_id' => ['sometimes', 'required', 'integer', 'exists:categories,id'],
        ];
    }
}
