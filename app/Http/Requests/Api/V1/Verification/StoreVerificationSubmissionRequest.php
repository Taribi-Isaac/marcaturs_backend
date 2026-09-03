<?php

namespace App\Http\Requests\Api\V1\Verification;

use App\Http\Requests\ApiFormRequest;

class StoreVerificationSubmissionRequest extends ApiFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'requirement_id' => ['required', 'integer', 'exists:verification_requirements,id'],
            'text_value' => ['nullable', 'string', 'max:5000'],
            'evidence' => [
                'nullable',
                'file',
                'max:'.config('verification.max_evidence_kilobytes'),
                'mimes:'.implode(',', config('verification.allowed_evidence_mimes')),
            ],
        ];
    }
}
