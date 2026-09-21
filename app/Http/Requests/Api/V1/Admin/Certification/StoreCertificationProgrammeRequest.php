<?php

namespace App\Http\Requests\Api\V1\Admin\Certification;

use Illuminate\Foundation\Http\FormRequest;

class StoreCertificationProgrammeRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string'],
            'learning_objectives' => ['sometimes', 'nullable', 'string'],
        ];
    }
}
