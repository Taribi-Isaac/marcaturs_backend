<?php

namespace App\Http\Requests\Api\V1\Verification;

use App\Http\Requests\ApiFormRequest;

class ResubmitVerificationSubmissionRequest extends ApiFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
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
