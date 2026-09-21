<?php

namespace App\Http\Requests\Api\V1\Admin\Certification;

use Illuminate\Foundation\Http\FormRequest;

class UpdateCertificationAssessmentRequest extends FormRequest
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
            'title' => ['sometimes', 'string', 'max:255'],
            'instructions' => ['sometimes', 'nullable', 'string'],
        ];
    }
}
