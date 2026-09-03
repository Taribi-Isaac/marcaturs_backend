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
            'business_id' => ['required', 'integer'],
            'campaign_id' => ['prohibited'],
            'ambassador_id' => ['prohibited'],
        ];
    }
}
