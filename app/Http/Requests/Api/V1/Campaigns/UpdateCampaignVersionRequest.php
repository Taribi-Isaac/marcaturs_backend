<?php

namespace App\Http\Requests\Api\V1\Campaigns;

use App\Http\Requests\ApiFormRequest;

class UpdateCampaignVersionRequest extends ApiFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return CampaignVersionTermRules::draft(requiredProductName: false);
    }
}
