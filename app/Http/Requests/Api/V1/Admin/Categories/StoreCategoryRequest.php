<?php

namespace App\Http\Requests\Api\V1\Admin\Categories;

use App\Enums\CategoryListingStatus;
use App\Http\Requests\ApiFormRequest;
use Illuminate\Validation\Rule;

class StoreCategoryRequest extends ApiFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255', 'unique:categories,name'],
            'slug' => ['nullable', 'string', 'max:255', 'unique:categories,slug'],
            'description' => ['nullable', 'string', 'max:5000'],
            'listing_status' => ['sometimes', Rule::enum(CategoryListingStatus::class)],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:10000'],
        ];
    }
}
