<?php

namespace App\Http\Requests\Api\V1\Admin\Certification;

use Illuminate\Foundation\Http\FormRequest;

class UpdateCertificationProgrammeVersionRequest extends FormRequest
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
            'fee_amount_minor' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'fee_currency' => ['sometimes', 'string', 'size:3'],
            'pass_mark_percent' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:100'],
        ];
    }
}
