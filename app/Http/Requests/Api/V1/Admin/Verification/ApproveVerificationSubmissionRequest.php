<?php

namespace App\Http\Requests\Api\V1\Admin\Verification;

use App\Http\Requests\ApiFormRequest;

class ApproveVerificationSubmissionRequest extends ApiFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
