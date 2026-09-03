<?php

namespace App\Http\Requests\Api\V1\Admin\Verification;

use App\Http\Requests\ApiFormRequest;

class ReviewVerificationSubmissionRequest extends ApiFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'max:2000'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
