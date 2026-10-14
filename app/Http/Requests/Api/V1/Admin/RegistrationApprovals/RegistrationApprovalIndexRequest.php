<?php

namespace App\Http\Requests\Api\V1\Admin\RegistrationApprovals;

use App\Enums\RegistrationApprovalStatus;
use App\Enums\Role;
use App\Http\Requests\ApiFormRequest;
use Illuminate\Validation\Rule;

class RegistrationApprovalIndexRequest extends ApiFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'status' => ['sometimes', 'nullable', Rule::in(RegistrationApprovalStatus::values())],
            'role' => ['sometimes', 'nullable', Rule::in(Role::publicRegistrationValues())],
            'q' => ['sometimes', 'nullable', 'string', 'max:255'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }
}
