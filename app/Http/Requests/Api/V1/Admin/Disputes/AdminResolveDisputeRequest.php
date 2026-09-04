<?php

namespace App\Http\Requests\Api\V1\Admin\Disputes;

use App\Http\Requests\ApiFormRequest;

class AdminResolveDisputeRequest extends ApiFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'decision_notes' => ['required', 'string', 'min:3', 'max:10000'],
            'action_notes' => ['required', 'string', 'min:3', 'max:10000'],
            'status' => ['prohibited'],
            'resolved_by_admin_user_id' => ['prohibited'],
            'closed_by_admin_user_id' => ['prohibited'],
            'resolved_at' => ['prohibited'],
            'closed_at' => ['prohibited'],
        ];
    }
}
