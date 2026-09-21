<?php

namespace App\Http\Requests\Api\V1\Admin\Certification;

use App\Enums\CertificationLessonContentType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCertificationLessonRequest extends FormRequest
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
            'description' => ['sometimes', 'nullable', 'string'],
            'content_type' => ['required', Rule::enum(CertificationLessonContentType::class)],
            'is_required' => ['sometimes', 'boolean'],
        ];
    }
}
