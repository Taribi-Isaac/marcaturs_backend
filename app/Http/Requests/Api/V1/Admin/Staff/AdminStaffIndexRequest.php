<?php

namespace App\Http\Requests\Api\V1\Admin\Staff;

use App\Enums\AccountStatus;
use App\Enums\AdminStaffRole;
use App\Http\Requests\ApiFormRequest;
use Illuminate\Validation\Rule;

class AdminStaffIndexRequest extends ApiFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'staff_role' => ['sometimes', 'nullable', Rule::in(AdminStaffRole::values())],
            'status' => ['sometimes', 'nullable', Rule::in(array_column(AccountStatus::cases(), 'value'))],
            'q' => ['sometimes', 'nullable', 'string', 'max:255'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }
}
