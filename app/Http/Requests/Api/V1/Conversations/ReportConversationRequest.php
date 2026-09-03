<?php

namespace App\Http\Requests\Api\V1\Conversations;

use App\Http\Requests\ApiFormRequest;

class ReportConversationRequest extends ApiFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:1', 'max:2000'],
        ];
    }
}
