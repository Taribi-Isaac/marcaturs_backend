<?php

namespace App\Http\Requests\Api\V1\Admin\Disputes;

use App\Http\Requests\ApiFormRequest;

class UpdateDisputeCategoryRequest extends ApiFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:120'],
            'description' => ['sometimes', 'nullable', 'string', 'max:500'],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:100000'],
            'code' => ['prohibited'],
        ];
    }
}
