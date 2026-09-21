<?php

namespace App\Http\Requests\Api\V1\Admin\Certification;

use Illuminate\Foundation\Http\FormRequest;

class StoreCertificationAssessmentRequest extends FormRequest
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
            'title' => ['required', 'string', 'max:255'],
            'instructions' => ['sometimes', 'nullable', 'string'],
        ];
    }
}
