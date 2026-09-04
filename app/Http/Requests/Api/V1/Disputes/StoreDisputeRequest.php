<?php

namespace App\Http\Requests\Api\V1\Disputes;

use App\Http\Requests\ApiFormRequest;

class StoreDisputeRequest extends ApiFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'category_id' => ['required', 'integer', 'exists:dispute_categories,id'],
            'description' => ['required', 'string', 'min:10', 'max:5000'],
            'status' => ['prohibited'],
            'reporter_user_id' => ['prohibited'],
            'accused_user_id' => ['prohibited'],
            'deal_id' => ['prohibited'],
            'commission_id' => ['prohibited'],
            'decision_notes' => ['prohibited'],
            'action_notes' => ['prohibited'],
            'resolved_by_admin_user_id' => ['prohibited'],
            'closed_by_admin_user_id' => ['prohibited'],
            'reference' => ['prohibited'],
            'customer_id' => ['prohibited'],
            'conversation_id' => ['prohibited'],
        ];
    }
}
