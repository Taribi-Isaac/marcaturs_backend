<?php

namespace App\Http\Requests\Api\V1\Campaigns;

use App\Http\Requests\ApiFormRequest;

class VerifyCampaignExtensionRequest extends ApiFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'reference' => ['required', 'string', 'max:64'],
        ];
    }
}
