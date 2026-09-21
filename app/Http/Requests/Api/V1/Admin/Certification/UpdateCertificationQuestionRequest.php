<?php

namespace App\Http\Requests\Api\V1\Admin\Certification;

use App\Enums\CertificationQuestionType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCertificationQuestionRequest extends FormRequest
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
            'prompt' => ['sometimes', 'string'],
            'type' => ['sometimes', Rule::enum(CertificationQuestionType::class)],
            'options' => ['sometimes', 'array', 'min:2'],
            'options.*.label' => ['required_with:options', 'string', 'max:1000'],
            'options.*.is_correct' => ['required_with:options', 'boolean'],
        ];
    }
}
