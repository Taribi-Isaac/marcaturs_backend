<?php

namespace App\Http\Requests\Api\V1\Admin\Certification;

use Illuminate\Foundation\Http\FormRequest;

class ReorderCertificationModulesRequest extends FormRequest
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
            'module_ids' => ['required', 'array', 'min:1'],
            'module_ids.*' => ['integer', 'distinct'],
        ];
    }
}
