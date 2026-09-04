<?php

namespace App\Http\Requests\Api\V1\Admin\Disputes;

use App\Http\Requests\ApiFormRequest;

class AdminRequestEvidenceRequest extends ApiFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:3', 'max:5000'],
            'status' => ['prohibited'],
            'note' => ['prohibited'],
        ];
    }
}
