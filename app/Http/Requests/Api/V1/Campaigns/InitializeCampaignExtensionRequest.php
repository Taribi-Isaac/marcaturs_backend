<?php

namespace App\Http\Requests\Api\V1\Campaigns;

use App\Http\Requests\ApiFormRequest;

class InitializeCampaignExtensionRequest extends ApiFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'package_id' => ['required', 'integer', 'exists:campaign_extension_packages,id'],
        ];
    }
}
