<?php

namespace App\Http\Requests\Api\V1\Admin\Certification;

use Illuminate\Foundation\Http\FormRequest;

class ReorderCertificationQuestionsRequest extends FormRequest
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
            'question_ids' => ['required', 'array', 'min:1'],
            'question_ids.*' => ['required', 'integer', 'distinct'],
        ];
    }
}
