<?php

namespace App\Http\Requests\Api\V1\Admin\Certification;

use Illuminate\Foundation\Http\FormRequest;

class ReorderCertificationResourcesRequest extends FormRequest
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
            'resource_ids' => ['required', 'array', 'min:1'],
            'resource_ids.*' => ['integer', 'distinct'],
        ];
    }
}
