<?php

namespace App\Http\Requests\Api\V1\Admin\Users;

use App\Enums\AccountStatus;
use App\Enums\Role;
use App\Http\Requests\ApiFormRequest;
use Illuminate\Validation\Rule;

class AdminUserIndexRequest extends ApiFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'role' => ['sometimes', Rule::in([Role::Business->value, Role::Ambassador->value])],
            'status' => ['sometimes', Rule::enum(AccountStatus::class)],
            'q' => ['sometimes', 'nullable', 'string', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:'.(int) config('api.pagination.max_per_page')],
        ];
    }
}
