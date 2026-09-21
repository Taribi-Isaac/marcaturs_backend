<?php

namespace App\Http\Requests\Api\V1\Admin\Certification;

use App\Enums\CertificationLessonContentType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCertificationLessonRequest extends FormRequest
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
            'description' => ['sometimes', 'nullable', 'string'],
            'content_type' => ['sometimes', Rule::enum(CertificationLessonContentType::class)],
            'is_required' => ['sometimes', 'boolean'],
        ];
    }
}
