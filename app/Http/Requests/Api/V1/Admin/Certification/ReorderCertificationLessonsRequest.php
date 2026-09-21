<?php

namespace App\Http\Requests\Api\V1\Admin\Certification;

use Illuminate\Foundation\Http\FormRequest;

class ReorderCertificationLessonsRequest extends FormRequest
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
            'lesson_ids' => ['required', 'array', 'min:1'],
            'lesson_ids.*' => ['integer', 'distinct'],
        ];
    }
}
