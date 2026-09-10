<?php

namespace App\Http\Requests\Api\V1\Campaigns;

use App\Http\Requests\ApiFormRequest;

class StoreCampaignCoverRequest extends ApiFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'file' => [
                'required',
                'file',
                'max:'.(int) config('campaigns.max_resource_kilobytes'),
                'mimes:jpeg,jpg,png,webp',
            ],
        ];
    }
}
