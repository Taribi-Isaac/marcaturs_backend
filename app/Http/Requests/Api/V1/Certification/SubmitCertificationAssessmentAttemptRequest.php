<?php

namespace App\Http\Requests\Api\V1\Certification;

use Illuminate\Foundation\Http\FormRequest;

class SubmitCertificationAssessmentAttemptRequest extends FormRequest
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
            'answers' => ['required', 'array', 'min:1'],
            'answers.*.question_id' => ['required', 'integer', 'distinct'],
            'answers.*.selected_option_id' => ['required', 'integer'],
        ];
    }
}
