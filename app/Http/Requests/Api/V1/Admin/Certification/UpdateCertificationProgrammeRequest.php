<?php

namespace App\Http\Requests\Api\V1\Admin\Certification;

use App\Enums\CertificationProgrammeStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCertificationProgrammeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string'],
            'learning_objectives' => ['sometimes', 'nullable', 'string'],
            'status' => [
                'sometimes',
                'string',
                Rule::in([
                    CertificationProgrammeStatus::Archived->value,
                    CertificationProgrammeStatus::Unpublished->value,
                ]),
            ],
        ];
    }
}
