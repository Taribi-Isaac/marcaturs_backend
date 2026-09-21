<?php

namespace App\Http\Requests\Api\V1\Conversations;

use App\Http\Requests\ApiFormRequest;

class StoreConversationRequest extends ApiFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $user = $this->user();

        if ($user?->isBusiness()) {
            return [
                'ambassador_id' => ['required', 'integer'],
                'campaign_id' => ['prohibited'],
                'business_id' => ['prohibited'],
            ];
        }

        return [
            'business_id' => ['required_without:campaign_id', 'prohibits:campaign_id', 'integer'],
            'campaign_id' => ['required_without:business_id', 'prohibits:business_id', 'integer'],
            'ambassador_id' => ['prohibited'],
        ];
    }
}
