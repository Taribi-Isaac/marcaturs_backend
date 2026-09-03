<?php

namespace App\Http\Requests\Api\V1\Admin\Campaigns;

use App\Http\Requests\ApiFormRequest;

class ReviewCampaignRequest extends ApiFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'max:2000'],
        ];
    }
}
