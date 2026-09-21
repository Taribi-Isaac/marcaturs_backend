<?php

namespace App\Http\Requests\Api\V1\Admin\Certification;

use App\Enums\CertificationQuestionType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCertificationQuestionRequest extends FormRequest
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
            'prompt' => ['required', 'string'],
            'type' => ['required', Rule::enum(CertificationQuestionType::class)],
            'options' => ['required', 'array', 'min:2'],
            'options.*.label' => ['required', 'string', 'max:1000'],
            'options.*.is_correct' => ['required', 'boolean'],
        ];
    }
}
