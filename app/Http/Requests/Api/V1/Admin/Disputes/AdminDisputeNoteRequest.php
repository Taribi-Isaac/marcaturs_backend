<?php

namespace App\Http\Requests\Api\V1\Admin\Disputes;

use App\Http\Requests\ApiFormRequest;

class AdminDisputeNoteRequest extends ApiFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'note' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'status' => ['prohibited'],
            'decision_notes' => ['prohibited'],
            'action_notes' => ['prohibited'],
        ];
    }
}
