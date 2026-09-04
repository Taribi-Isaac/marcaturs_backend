<?php

namespace App\Http\Requests\Api\V1\Admin\Disputes;

use App\Http\Requests\ApiFormRequest;

class StoreDisputeCategoryRequest extends ApiFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'code' => ['sometimes', 'nullable', 'string', 'max:64', 'unique:dispute_categories,code'],
            'description' => ['sometimes', 'nullable', 'string', 'max:500'],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:100000'],
        ];
    }
}
